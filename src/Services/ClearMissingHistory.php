<?php

namespace Pelican\AutoBackup\Services;

use App\Enums\SubuserPermission;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Pelican\AutoBackup\Models\BackupRun;
use RuntimeException;

class ClearMissingHistory
{
    public function handle(Server $server, User $actor, int $runId): void
    {
        foreach ([SubuserPermission::BackupRead, SubuserPermission::BackupDelete,
            SubuserPermission::ScheduleRead, SubuserPermission::ScheduleUpdate] as $permission) {
            if (!$actor->can($permission, $server)) {
                throw new AuthorizationException('You do not have permission to clear this history.');
            }
        }

        DB::transaction(function () use ($server, $runId) {
            $run = BackupRun::where('server_id', $server->id)->whereKey($runId)->lockForUpdate()->firstOrFail();
            if ($run->state !== 'finished' || !$run->finished_at || $run->active_server_id
                || !in_array($run->backup_result, ['success', 'failed'], true)) {
                throw new RuntimeException('Active or unresolved runs cannot be removed.');
            }
            if (!$run->backup_id || Backup::whereKey($run->backup_id)->exists()) {
                throw new RuntimeException('This entry does not belong to a deleted backup.');
            }
            if (!$run->history_cleared_at) {
                // Keep the occurrence key for scheduler deduplication, just as with failed history.
                $run->forceFill(['history_cleared_at' => now()])->save();
            }
        });
    }
}
