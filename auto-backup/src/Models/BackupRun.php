<?php

namespace Pelican\AutoBackup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Backup;

class BackupRun extends Model
{
    protected $table = 'auto_backup_runs';
    protected $guarded = ['id'];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_id');
    }
    protected function casts(): array
    {
        return [
            'settings' => 'array', 'context' => 'array', 'events' => 'array',
            'stopped_by_plugin' => 'boolean', 'scheduled_at' => 'immutable_datetime',
            'wake_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'stop_requested_at' => 'immutable_datetime',
            'manual_intervention_at' => 'immutable_datetime',
            'history_cleared_at' => 'immutable_datetime',
        ];
    }

    public function record(string $message): void
    {
        $this->events = [...($this->events ?? []), ['at' => now()->toIso8601String(), 'message' => $message]];
        $this->save();
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            'checking' => 'Checking server', 'warnings' => 'Warning phase',
            'commands', 'command_intent', 'prepared' => 'Preparation',
            'stop_intent', 'waiting_offline' => 'Waiting for server shutdown',
            'ready', 'creating' => 'Creating backup', 'monitoring' => 'Backup in progress',
            'rotating' => 'Rotating old auto backups',
            'restoring', 'start_intent', 'waiting_start' => 'Restoring server state',
            'attention' => 'Manual review required', 'finished' => 'Finished',
            default => $this->state,
        };
    }

    public static function resultLabel(string $value): string
    {
        return match ($value) {
            'pending' => 'Pending', 'success' => 'Successful', 'failed' => 'Failed',
            'warning' => 'Warning', 'unknown' => 'Unresolved', 'skipped' => 'Skipped',
            'manual_intervention' => 'Manual intervention', 'already_running' => 'Already running',
            default => $value,
        };
    }

    public function advance(string $state, int $delay = 0, ?int $timeout = null): void
    {
        $this->forceFill([
            'state' => $state, 'wake_at' => now()->addSeconds($delay),
            'deadline_at' => $timeout === null ? null : now()->addSeconds($timeout),
        ])->save();
        $this->record($state);
    }
}
