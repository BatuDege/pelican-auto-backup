<?php

namespace Pelican\AutoBackup\Services;

use App\Enums\SubuserPermission;
use App\Http\Controllers\Api\Client\Servers\BackupController;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pelican\AutoBackup\Models\BackupRun;
use RuntimeException;

class ManageBackups
{
    public function setLocked(Server $server, User $actor, int $id, bool $locked): void
    {
        $this->authorize($server, $actor);
        DB::transaction(function () use ($server, $actor, $id, $locked) {
            $backup = $this->find($server, $id);
            if (!$backup->completed_at || !$backup->is_successful) {
                throw new RuntimeException('Only completed successful backups can be locked or unlocked.');
            }
            // Explicit target state avoids inverting a lock changed meanwhile in Pelican.
            if ($backup->is_locked !== $locked) {
                app(BackupController::class)->toggleLock($this->request($actor), $server, $backup);
            }
        });
    }

    public function delete(Server $server, User $actor, int $id): void
    {
        $this->authorize($server, $actor);
        DB::transaction(function () use ($server, $actor, $id) {
            $backup = $this->find($server, $id);
            if (!$backup->completed_at || BackupRun::where('active_server_id', $server->id)->where('backup_id', $id)->exists()) {
                throw new RuntimeException('This backup still belongs to an active or unresolved run.');
            }
            if ($backup->is_locked) {
                throw new RuntimeException('This backup is locked. Unlock it before deleting.');
            }
            // Same native service and activity entry as Pelican's own backup tab.
            app(BackupController::class)->delete($this->request($actor), $server, $backup);
        });
    }

    private function authorize(Server $server, User $actor): void
    {
        if (!$actor->can(SubuserPermission::BackupRead, $server) || !$actor->can(SubuserPermission::BackupDelete, $server)) {
            throw new AuthorizationException('You do not have permission to manage these backups.');
        }
        $server->validateCurrentState();
    }

    private function find(Server $server, int $id): Backup
    {
        $backup = Backup::where('server_id', $server->id)->whereKey($id)->lockForUpdate()->firstOrFail();
        if (!BackupRun::where('server_id', $server->id)->where('backup_id', $backup->id)->where('backup_uuid', $backup->uuid)->exists()) {
            throw new AuthorizationException('This backup does not belong to the Auto Backup history of this server.');
        }
        return $backup;
    }

    private function request(User $actor): Request
    {
        return Request::create('/')->setUserResolver(fn () => $actor);
    }
}
