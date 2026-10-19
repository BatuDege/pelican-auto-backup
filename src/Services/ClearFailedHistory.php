<?php

namespace Pelican\AutoBackup\Services;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Pelican\AutoBackup\Models\BackupRun;

class ClearFailedHistory
{
    public function handle(Server $server, User $actor): int
    {
        foreach ([SubuserPermission::BackupRead, SubuserPermission::BackupDelete,
            SubuserPermission::ScheduleRead, SubuserPermission::ScheduleUpdate] as $permission) {
            if (!$actor->can($permission, $server)) {
                throw new AuthorizationException('You do not have permission to clear this history.');
            }
        }

        // Retain occurrence keys and backup ownership for scheduler deduplication and
        // retention. This operation never deletes a Pelican backup or releases a run.
        return BackupRun::where('server_id', $server->id)
            ->where('state', 'finished')->whereNotNull('finished_at')
            ->whereNull('active_server_id')->where('backup_result', 'failed')
            ->whereNull('history_cleared_at')
            ->update(['history_cleared_at' => now()]);
    }
}
