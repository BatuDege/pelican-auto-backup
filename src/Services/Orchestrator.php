<?php

namespace Pelican\AutoBackup\Services;

use Carbon\CarbonImmutable;
use Pelican\AutoBackup\Contracts\PanelGateway;
use Pelican\AutoBackup\Models\BackupRun;
use RuntimeException;
use Throwable;

class Orchestrator
{
    public function __construct(private PanelGateway $panel) {}

    public function tick(BackupRun $run): void
    {
        if (!$run->active_server_id || $run->state === 'attention' || $run->wake_at->isFuture()) { return; }
        try {
            $this->panel->authorize($run->server_id, $run->user_id, $run->settings);
        } catch (Throwable $e) {
            $this->attention($run, 'Permission / server state: ' . $e->getMessage());
            return;
        }
        try {
            $this->step($run);
        } catch (Throwable $e) {
            if (in_array($run->state, ['creating', 'stop_intent', 'command_intent', 'start_intent'], true)) {
                $this->attention($run, 'The action may have completed; it will not be retried automatically. ' . $e->getMessage());
            } elseif ($run->state === 'rotating') {
                $run->rotation_result = 'warning';
                $run->record('Rotation: ' . $e->getMessage());
                $run->advance('restoring');
            } elseif (in_array($run->state, ['restoring', 'waiting_start'], true)) {
                $run->restart_result = 'failed';
                $run->error = $e->getMessage();
                $this->finish($run);
            } else {
                $this->fail($run, $e->getMessage());
            }
        }
    }

    private function step(BackupRun $run): void
    {
        $s = $run->settings;
        switch ($run->state) {
            case 'checking':
                $this->panel->preflight($run);
                $status = $this->panel->status($run);
                if (!in_array($status, ['running', 'offline'], true)) {
                    $this->wait($run, $s['shutdown_timeout'], 'Could not confirm a stable server state.');
                    return;
                }
                $run->original_state = $status;
                $run->save();
                // An already-offline server also waits until the scheduled time.
                $run->advance('warnings');
                return;
            case 'warnings':
                if ($this->panel->manuallyControlled($run)) { throw new RuntimeException('Manual power action detected; run aborted.'); }
                if ($run->original_state === 'running' && !$s['live']) {
                    $warnings = $s['warnings'];
                    usort($warnings, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);
                    $index = (int) ($run->context['warning_index'] ?? 0);
                    if (isset($warnings[$index])) {
                        $warning = $warnings[$index];
                        $at = $run->scheduled_at->subSeconds($warning['seconds']);
                        if ($at->isFuture()) { $run->wake_at = $at; $run->save(); return; }
                        if ($this->panel->status($run) !== 'running') { throw new RuntimeException('The server state changed during the warnings.'); }
                        $remaining = max(0, (int) ceil(now()->diffInSeconds($run->scheduled_at, false)));
                        // Never send stale "five minutes" warnings after a worker outage.
                        if ($at->diffInSeconds(now()) > 20) { throw new RuntimeException('A warning was missed; the server will not be stopped late.'); }
                        $this->send($run, $warning['command'], 'warning_index', $index + 1, 'warnings', $remaining);
                        return;
                    }
                }
                if ($run->scheduled_at->isFuture()) { $run->wake_at = $run->scheduled_at; $run->save(); return; }
                if ($run->scheduled_at->diffInSeconds(now()) > 90) { throw new RuntimeException('Scheduled time missed; run skipped.'); }
                $run->advance('commands');
                return;
            case 'commands':
                if ($this->panel->manuallyControlled($run)) { throw new RuntimeException('Manual power action detected.'); }
                if ($run->original_state === 'running') {
                    if ($this->panel->status($run) !== 'running') { throw new RuntimeException('The server is no longer running before preparation.'); }
                    $index = (int) ($run->context['command_index'] ?? 0);
                    if (isset($s['commands'][$index])) {
                        $this->send($run, $s['commands'][$index]['command'], 'command_index', $index + 1, 'commands');
                        return;
                    }
                }
                $run->advance('prepared', $run->original_state === 'running' && $s['commands'] ? $s['pre_delay'] : 0);
                return;
            case 'prepared':
                $this->panel->preflight($run);
                if ($this->panel->manuallyControlled($run)) { throw new RuntimeException('Manual power action detected.'); }
                $status = $this->panel->status($run);
                if ($status !== $run->original_state) { throw new RuntimeException('The server state changed before the backup.'); }
                if (!$s['live'] && $status === 'running') {
                    $run->stop_requested_at = now();
                    $run->advance('stop_intent');
                    $this->panel->power($run, 'stop');
                    $run->stopped_by_plugin = true;
                    $run->advance('waiting_offline', 2, $s['shutdown_timeout']);
                } else { $run->advance('ready'); }
                return;
            case 'waiting_offline':
                if ($this->panel->manuallyControlled($run)) { $this->attention($run, 'Manual power action during shutdown.'); return; }
                if ($this->panel->status($run) === 'offline') { $run->advance('ready'); return; }
                $this->wait($run, $s['shutdown_timeout'], 'Shutdown timeout: offline state not confirmed.');
                return;
            case 'ready':
                if ($this->panel->manuallyControlled($run)) { $this->attention($run, 'Manual power action before backup creation.'); return; }
                $status = $this->panel->status($run);
                $expected = $s['live'] ? $run->original_state : 'offline';
                if ($status !== $expected) { throw new RuntimeException('Backup aborted: expected server state ' . $expected . ' not confirmed.'); }
                $this->panel->preflight($run);
                $run->advance('creating');
                $this->panel->create($run);
                $run->advance('monitoring', 3, $s['backup_timeout']);
                return;
            case 'creating':
                if ($run->backup_id) { $run->advance('monitoring', 0, $s['backup_timeout']); return; }
                $this->attention($run, 'Worker interrupted during backup creation. Check Wings and Pelican manually.');
                return;
            case 'monitoring':
                $result = $this->panel->backupState($run);
                if ($result === 'pending') {
                    if ($run->deadline_at->isPast()) {
                        $run->backup_result = 'unknown';
                        $this->attention($run, 'Backup timeout: completion on Wings is unconfirmed. No restart or new backup will be attempted.');
                    } else { $run->wake_at = now()->addSeconds(5); $run->save(); }
                    return;
                }
                if ($result === 'unknown') { $run->backup_result = 'unknown'; $this->attention($run, 'Backup record is missing or completion is unconfirmed.'); return; }
                $run->backup_result = $result;
                $run->advance($result === 'success' ? 'rotating' : 'restoring');
                return;
            case 'rotating':
                $result = $this->panel->rotate($run);
                if ($result['deleted']) { $run->record('Auto backup deleted: ' . $result['deleted']); }
                if ($result['done']) {
                    $run->rotation_result = $result['warning'] ? 'warning' : 'success';
                    if ($result['warning']) { $run->record($result['warning']); }
                    $run->advance('restoring');
                } else { $run->wake_at = now()->addSecond(); $run->save(); }
                return;
            case 'restoring':
                $restart = $run->backup_result === 'success' ? $s['restart_success'] : $s['restart_failure'];
                if ($run->original_state !== 'running' || !$run->stopped_by_plugin || !$restart) {
                    $run->restart_result = 'skipped'; $this->finish($run); return;
                }
                if ($this->panel->manuallyControlled($run)) {
                    $run->restart_result = 'manual_intervention';
                    $run->record('Restart skipped after a manual power action.'); $this->finish($run); return;
                }
                if ($this->panel->hasPendingBackup($run->server_id)) {
                    $this->attention($run, 'A Pelican backup is still active; restart skipped.'); return;
                }
                $status = $this->panel->status($run);
                if ($status === 'running') { $run->restart_result = 'already_running'; $this->finish($run); return; }
                if ($status !== 'offline') {
                    $this->wait($run, $s['shutdown_timeout'], 'Cannot restart: offline state not confirmed.'); return;
                }
                $run->advance('start_intent');
                $this->panel->power($run, 'start');
                $run->advance('waiting_start', 3, $s['start_timeout']);
                return;
            case 'waiting_start':
                if ($this->panel->status($run) === 'running') { $run->restart_result = 'success'; $this->finish($run); return; }
                $this->wait($run, $s['start_timeout'], 'Start timeout: running state not confirmed.');
                return;
            case 'stop_intent':
            case 'start_intent':
            case 'command_intent':
                $this->attention($run, 'Worker interrupted during an external action. Manual review is required before retrying.');
                return;
            default:
                $this->attention($run, 'Unknown run state.');
        }
    }

    private function send(BackupRun $run, string $command, string $key, int $index, string $next, int $seconds = 0): void
    {
        $text = Settings::expand($command, $this->panel->serverName($run->server_id),
            $run->scheduled_at->setTimezone($run->settings['timezone']), $run->backup_name, $seconds);
        $run->advance('command_intent');
        $this->panel->command($run, $text);
        $run->context = array_replace($run->context ?? [], [$key => $index]);
        $run->advance($next);
    }

    private function wait(BackupRun $run, int $timeout, string $message): void
    {
        $run->deadline_at ??= CarbonImmutable::now()->addSeconds($timeout);
        if ($run->deadline_at->isPast()) { throw new RuntimeException($message); }
        $run->wake_at = now()->addSeconds(3);
        $run->save();
    }

    private function fail(BackupRun $run, string $message): void
    {
        $run->backup_result = 'failed';
        $run->error = $message;
        $run->record($message);
        $run->advance('restoring');
    }

    private function attention(BackupRun $run, string $message): void
    {
        if ($run->state === 'creating') { $run->backup_result = 'unknown'; }
        if ($run->state === 'start_intent') { $run->restart_result = 'unknown'; }
        $run->error = $message;
        $run->advance('attention');
        $run->record($message);
        // Keep active_server_id reserved, even after a timeout, until explicitly reconciled.
    }

    private function finish(BackupRun $run): void
    {
        if ($run->rotation_result === 'pending') { $run->rotation_result = 'skipped'; }
        $run->forceFill(['state' => 'finished', 'active_server_id' => null, 'finished_at' => now()])->save();
        $run->record('Run finished.');
    }
}
