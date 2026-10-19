<?php

namespace Pelican\AutoBackup\Services;

use App\Enums\SubuserPermission;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\InitiateBackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pelican\AutoBackup\Contracts\PanelGateway;
use Pelican\AutoBackup\Models\BackupRun;
use RuntimeException;

class PelicanGateway implements PanelGateway
{
    public static function permissions(array $settings): array
    {
        $permissions = [SubuserPermission::BackupRead, SubuserPermission::BackupCreate,
            SubuserPermission::BackupDelete, SubuserPermission::ScheduleRead, SubuserPermission::ScheduleUpdate];
        if (!$settings['live']) {
            $permissions[] = SubuserPermission::ControlStop;
            if ($settings['restart_success'] || $settings['restart_failure']) {
                $permissions[] = SubuserPermission::ControlStart;
            }
        }
        if ($settings['commands'] || (!$settings['live'] && $settings['warnings'])) {
            $permissions[] = SubuserPermission::ControlConsole;
        }
        return $permissions;
    }

    public function authorize(int $server, int $user, array $settings): void
    {
        $model = Server::findOrFail($server);
        $actor = User::findOrFail($user);
        foreach (self::permissions($settings) as $permission) {
            if (!$actor->can($permission, $model)) {
                throw new RuntimeException('Missing permission: ' . $permission->value);
            }
        }
        $model->validateCurrentState();
    }

    public function preflight(BackupRun $run): void
    {
        $server = Server::findOrFail($run->server_id);
        $server->validateCurrentState();
        if ($this->hasPendingBackup($server->id)) {
            throw new RuntimeException('A Pelican backup is already active.');
        }
        if ($server->backup_limit <= 0 || $server->backups()->nonFailed()->count() >= $server->backup_limit) {
            throw new RuntimeException('No free Pelican backup slot. Ask an administrator to allow retained auto backups + other backups + 1 working slot in this server backup limit. Locked backups may need extra slots.');
        }
        $period = (int) config('backups.throttles.period', 600);
        $limit = (int) config('backups.throttles.limit', 2);
        if ($period > 0 && $server->backups()->nonFailed()->where('created_at', '>=', now()->subSeconds($period))->count() >= $limit) {
            throw new RuntimeException("Pelican backup rate limit reached: $limit backups per $period seconds per server. Wait for the window to expire or ask an administrator to adjust BACKUP_THROTTLE_LIMIT / BACKUP_THROTTLE_PERIOD in the Panel .env file.");
        }
    }

    public function hasPendingBackup(int $server): bool
    {
        return Backup::where('server_id', $server)->whereNull('completed_at')->exists();
    }

    public function status(BackupRun $run): string
    {
        // Deliberately bypass Server::retrieveStatus()'s 15-second status cache.
        $details = app(DaemonServerRepository::class)->setServer(Server::findOrFail($run->server_id))->getDetails(5);
        return $details['state'] ?? 'unknown';
    }

    public function command(BackupRun $run, string $command): void
    {
        $server = Server::findOrFail($run->server_id);
        Http::daemon($server->node)->connectTimeout(5)->timeout(15)
            ->post("/api/servers/{$server->uuid}/commands", ['commands' => [$command]])->throw();
    }

    public function power(BackupRun $run, string $action): void
    {
        $server = Server::findOrFail($run->server_id);
        app(DaemonServerRepository::class)->setServer($server)->getHttpClient()
            ->connectTimeout(5)->timeout(15)
            ->withHeaders(['X-Auto-Backup-Run' => (string) $run->id])
            ->post("/api/servers/{$server->uuid}/power", ['action' => $action])->throw();
    }

    public function create(BackupRun $run): void
    {
        $this->preflight($run);
        // The native service and this association commit atomically. The durable creating
        // intent lives outside the transaction; an ambiguous crash is never auto-retried.
        DB::transaction(function () use ($run) {
            $backup = app(InitiateBackupService::class)->setIsScheduled(true)
                ->setIgnoredFiles([])->handle(Server::findOrFail($run->server_id), $run->backup_name, false);
            $run->forceFill(['backup_id' => $backup->id, 'backup_uuid' => $backup->uuid])->save();
        });
    }

    public function backupState(BackupRun $run): string
    {
        $backup = Backup::where('server_id', $run->server_id)->where('id', $run->backup_id)
            ->where('uuid', $run->backup_uuid)->first();
        if (!$backup) { return 'unknown'; }
        if (!$backup->completed_at) { return 'pending'; }
        if ($backup->is_successful) { return 'success'; }
        // Pelican's orphan-pruner also sets completed_at, without confirmation from
        // Wings. Only a recorded failure callback permits restart-after-failure.
        $confirmed = ActivityLog::where('event', 'server:backup.fail')
            ->whereHas('subjects', fn ($query) => $query->where('subject_type', $backup->getMorphClass())
                ->where('subject_id', $backup->id))->exists();
        return $confirmed ? 'failed' : 'unknown';
    }

    public function rotate(BackupRun $run): array
    {
        // One deletion per short job. Manual backups are never selected by their name.
        $owned = BackupRun::where('server_id', $run->server_id)->whereNotNull('backup_id')->select('backup_id');
        $query = Backup::where('server_id', $run->server_id)->whereIn('id', $owned)
            ->where('is_successful', true)->whereNotNull('completed_at');
        if ((clone $query)->count() <= $run->settings['retention']) {
            return ['done' => true, 'warning' => null, 'deleted' => null];
        }
        $id = (clone $query)->where('id', '!=', $run->backup_id)->where('is_locked', false)
            ->oldest('created_at')->orderBy('id')->value('id');
        if (!$id) {
            return ['done' => true, 'warning' => 'Retention exceeded: all remaining auto backups are locked.', 'deleted' => null];
        }
        return DB::transaction(function () use ($id) {
            $backup = Backup::whereKey($id)->lockForUpdate()->first();
            if (!$backup || $backup->is_locked) {
                return ['done' => false, 'warning' => null, 'deleted' => null];
            }
            $uuid = $backup->uuid;
            app(DeleteBackupService::class)->handle($backup);
            return ['done' => false, 'warning' => null, 'deleted' => $uuid];
        });
    }

    public function manuallyControlled(BackupRun $run): bool
    {
        if ($run->fresh()?->manual_intervention_at) { return true; }
        return ActivityLog::where('timestamp', '>=', $run->created_at)
            ->where('event', 'like', 'server:power.%')
            ->whereHas('subjects', fn ($query) => $query->where('subject_type', (new Server())->getMorphClass())
                ->where('subject_id', $run->server_id))->exists();
    }

    public function serverName(int $server): string { return Server::findOrFail($server)->name; }
}
