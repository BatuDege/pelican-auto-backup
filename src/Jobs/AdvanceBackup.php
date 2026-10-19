<?php

namespace Pelican\AutoBackup\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Pelican\AutoBackup\Models\BackupRun;
use Pelican\AutoBackup\Services\Orchestrator;
use Pelican\AutoBackup\Services\RuntimeRequirements;

class AdvanceBackup implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;
    public int $uniqueFor = 120;
    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}
    public function uniqueId(): string { return (string) $this->runId; }

    public function handle(Orchestrator $orchestrator): void
    {
        RuntimeRequirements::check();
        $lock = Cache::lock('auto-backup:run:' . $this->runId, 180);
        if (!$lock->get()) { return; }
        try {
            $run = BackupRun::find($this->runId);
            if (!$run || !$run->active_server_id || $run->state === 'attention') { return; }
            // One external action per job keeps the worker below retry_after and lock TTL.
            $orchestrator->tick($run);
        } finally { $lock->release(); }
        if ($run->active_server_id && $run->state !== 'attention') {
            self::dispatch($run->id)->delay($run->wake_at);
        }
    }
}
