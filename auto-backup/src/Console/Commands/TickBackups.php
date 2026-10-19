<?php

namespace Pelican\AutoBackup\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pelican\AutoBackup\Jobs\AdvanceBackup;
use Pelican\AutoBackup\Models\BackupConfig;
use Pelican\AutoBackup\Models\BackupRun;
use Pelican\AutoBackup\Services\RunManager;
use Pelican\AutoBackup\Services\Settings;
use Pelican\AutoBackup\Services\RuntimeRequirements;
use Throwable;

class TickBackups extends Command
{
    protected $signature = 'auto-backup:tick';
    protected $description = 'Plan due auto backups and recover interrupted workers';

    public function handle(RunManager $manager): int
    {
        try {
            RuntimeRequirements::check();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
        BackupConfig::where('enabled', true)->each(function (BackupConfig $config) use ($manager) {
            try {
                DB::transaction(function () use ($config, $manager) {
                    $config = BackupConfig::whereKey($config->id)->lockForUpdate()->first();
                    if (!$config?->enabled) { return; }
                    $s = $config->settings;
                    $due = $config->next_due_at;
                    if (!$due) {
                        $config->next_due_at = Settings::next($s, CarbonImmutable::now()->addSeconds(Settings::lead($s)));
                        $config->save(); return;
                    }
                    // Dispatch up to one minute early so a 10-second warning is not missed.
                    if ($due->subSeconds(Settings::lead($s) + 60)->isFuture()) { return; }
                    if (!$due->addSeconds(90)->isPast() && !BackupRun::where('active_server_id', $config->server_id)->exists()) {
                        $run = $manager->create($config, $due);
                        $run->wake_at = $due->subSeconds(Settings::lead($s));
                        $run->save();
                    } else {
                        Log::notice('Auto Backup: missed/overlapping occurrence skipped', ['server_id' => $config->server_id, 'due' => $due->toIso8601String()]);
                    }
                    $config->next_due_at = Settings::next($s, $due->max(CarbonImmutable::now()));
                    $config->save();
                });
            } catch (UniqueConstraintViolationException) {
                // Another scheduler or manual run won. The next tick advances this occurrence.
                $config->refresh();
                $config->next_due_at = Settings::next($config->settings, CarbonImmutable::now()->max($config->next_due_at));
                $config->save();
            } catch (Throwable $e) {
                Log::warning('Auto Backup scheduling failed', ['server_id' => $config->server_id, 'error' => $e->getMessage()]);
            }
        });
        // The database reservation outlives lost jobs and cache locks. Delays are hints;
        // every tick checks wake_at again under the per-run lock.
        BackupRun::whereNotNull('active_server_id')->where('state', '!=', 'attention')
            ->where('wake_at', '<=', now()->addMinute())->each(fn ($run) => AdvanceBackup::dispatch($run->id)->delay($run->wake_at));
        return self::SUCCESS;
    }
}
