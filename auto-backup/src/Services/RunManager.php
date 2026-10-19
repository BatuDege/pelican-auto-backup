<?php

namespace Pelican\AutoBackup\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pelican\AutoBackup\Contracts\PanelGateway;
use Pelican\AutoBackup\Models\BackupConfig;
use Pelican\AutoBackup\Models\BackupRun;
use RuntimeException;

class RunManager
{
    public function __construct(private PanelGateway $panel) {}

    public function save(int $server, int $user, bool $enabled, array $settings): BackupConfig
    {
        if ($enabled) { RuntimeRequirements::check(worker: false); }
        $settings = Settings::validate($settings);
        $this->panel->authorize($server, $user, $settings);
        // Start with the next complete warning window; no surprise immediate shutdown on save.
        return BackupConfig::updateOrCreate(['server_id' => $server], [
            'user_id' => $user, 'enabled' => $enabled, 'settings' => $settings,
            'next_due_at' => $enabled ? Settings::next($settings, CarbonImmutable::now()->addSeconds(Settings::lead($settings))) : null,
        ]);
    }

    public function create(BackupConfig $config, ?CarbonImmutable $due = null, bool $manual = false): BackupRun
    {
        RuntimeRequirements::check(worker: false);
        $settings = Settings::validate($config->settings);
        $this->panel->authorize($config->server_id, $config->user_id, $settings);
        $due ??= CarbonImmutable::now()->addSeconds(Settings::lead($settings));
        // A repeated wall-clock minute at the autumn DST transition runs once only.
        $occurrence = $manual ? Str::uuid()->toString() : $config->server_id . ':' . $settings['timezone'] . ':' . $due->setTimezone($settings['timezone'])->format('Y-m-d H:i');
        $name = Settings::expand($settings['name'], $this->panel->serverName($config->server_id), $due->setTimezone($settings['timezone']));
        // Unique constraints arbitrate across scheduler, UI and workers; no expiring reservation.
        return BackupRun::create([
            'server_id' => $config->server_id, 'active_server_id' => $config->server_id,
            'user_id' => $config->user_id, 'occurrence' => $occurrence, 'settings' => $settings,
            'scheduled_at' => $due, 'wake_at' => now(), 'backup_name' => mb_substr($name, 0, 191),
            'context' => [], 'events' => [],
        ]);
    }

    public function acknowledge(BackupRun $run, int $actor): void
    {
        $this->panel->authorize($run->server_id, $actor, $run->settings);
        $lock = Cache::lock('auto-backup:run:' . $run->id, 180);
        if (!$lock->get()) { throw new RuntimeException('This run is currently being processed.'); }
        try {
            $run->refresh();
            if ($run->state !== 'attention' || $this->panel->hasPendingBackup($run->server_id)) {
                throw new RuntimeException('Only unresolved runs with no active Pelican backup can be released.');
            }
            $run->record('Unresolved state released after manual review by user #' . $actor . '. No power action was performed.');
            $run->forceFill(['state' => 'finished', 'active_server_id' => null, 'finished_at' => now()])->save();
        } finally { $lock->release(); }
    }
}
