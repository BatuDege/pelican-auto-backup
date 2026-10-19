<x-filament-panels::page>
    <x-filament::section heading="Automatic backups">
        <p>Backups use the native Pelican backup system. A server that was already offline is never started automatically.</p>
        <p><strong>Backup slots:</strong> this server allows {{ $this->getRecord()->backup_limit }} backups.
            Required capacity = auto backups to keep + other backups + 1 free working slot.
            For example, 7 auto backups + 2 manual backups require 10 total slots.
            The new backup must succeed before an old auto backup is removed. Locked backups may require extra slots.
            Ask your administrator to increase the server backup limit if needed.</p>
        @if((int) config('backups.throttles.period', 600) > 0)
            <p><strong>Pelican rate limit:</strong> {{ (int) config('backups.throttles.limit', 2) }} backups
                per {{ (int) config('backups.throttles.period', 600) }} seconds per server, shared with manual backups and native schedules.
                If this is too low, ask your administrator to adjust the Panel backup throttle settings described in the plugin README.</p>
        @else
            <p><strong>Pelican rate limit:</strong> the Panel backup creation throttle is disabled.</p>
        @endif
    </x-filament::section>
    <form id="auto-backup-settings" wire:submit="save" class="space-y-6">
        {{ $this->form }}
    </form>
    <div wire:poll.5s>
        @php
            $overview = $this->overview();
        @endphp
        <x-filament::section heading="Status and history">
            <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem">
                {{ $this->clearFailedHistoryAction }}
            </div>
            <p>Next scheduled run:
                {{ $overview['config']?->next_due_at?->setTimezone($overview['config']->settings['timezone'])->format('Y-m-d H:i:s T') ?? 'Disabled' }}
            </p>
            @if($overview['active'])
                <p>Current run #{{ $overview['active']->id }}: <strong>{{ $overview['active']->stateLabel() }}</strong></p>
                @if($overview['active']->error)<p role="alert">{{ $overview['active']->error }}</p>@endif
            @endif
            <div style="display: grid; gap: 1rem; margin-top: 1rem">
                @forelse($overview['runs'] as $run)
                    @php
                        $backup = $run->backup;
                        if ($backup && $backup->uuid !== $run->backup_uuid) { $backup = null; }
                        $canManage = user()?->can(\App\Enums\SubuserPermission::BackupDelete, $this->getRecord());
                    @endphp
                    <article wire:key="backup-run-{{ $run->id }}" aria-labelledby="backup-title-{{ $run->id }}"
                        style="border: 1px solid color-mix(in srgb, currentColor 25%, transparent); border-radius: .75rem; padding: 1.25rem">
                        <div style="display: flex; align-items: start; justify-content: space-between; flex-wrap: wrap; gap: .75rem">
                            <div style="min-width: 0; overflow-wrap: anywhere">
                                <h3 id="backup-title-{{ $run->id }}" style="font-weight: 600">{{ $backup?->name ?? $run->backup_name }}</h3>
                                <p style="font-size: .875rem; opacity: .65">Run #{{ $run->id }} · {{ $run->created_at->setTimezone($run->settings['timezone'])->format('Y-m-d H:i:s T') }}</p>
                            </div>
                            @if($backup)
                                <x-filament::badge :color="$backup->is_locked ? 'warning' : 'gray'" :icon="$backup->is_locked ? \App\Enums\TablerIcon::Lock : \App\Enums\TablerIcon::LockOpen">
                                    {{ $backup->is_locked ? 'Locked' : 'Unlocked' }}
                                </x-filament::badge>
                            @elseif($run->backup_id)
                                <x-filament::badge color="gray">Backup no longer exists</x-filament::badge>
                            @else
                                <x-filament::badge color="gray">No backup created yet</x-filament::badge>
                            @endif
                        </div>
                        <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin: 1rem 0">
                            @foreach(['State' => $run->stateLabel(), 'Backup' => $run::resultLabel($run->backup_result), 'Rotation' => $run::resultLabel($run->rotation_result), 'Restart' => $run::resultLabel($run->restart_result), 'Duration' => ((int) $run->created_at->diffInSeconds($run->finished_at ?? now())) . ' s'] as $label => $value)
                                <div><dt style="font-size: .875rem; opacity: .65">{{ $label }}</dt><dd>{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                        @if($run->error)<p role="alert" style="margin-bottom: 1rem">{{ $run->error }}</p>@endif
                        @if($backup && $backup->completed_at && $canManage)
                            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-bottom: 1rem">
                                @if($backup->is_successful)
                                    @if($backup->is_locked)
                                        {{ ($this->unlockBackupAction)(['backup' => $backup->id]) }}
                                    @else
                                        {{ ($this->lockBackupAction)(['backup' => $backup->id]) }}
                                    @endif
                                @endif
                                @if(!$backup->is_locked && !$run->active_server_id)
                                    {{ ($this->deleteBackupAction)(['backup' => $backup->id]) }}
                                @elseif($backup->is_locked)
                                    <span style="font-size: .875rem; opacity: .65">Unlock before deleting.</span>
                                @else
                                    <span style="font-size: .875rem; opacity: .65">Deletion is available once the run has finished.</span>
                                @endif
                            </div>
                        @endif
                        @if(!$backup && $run->backup_id && $run->state === 'finished' && $run->finished_at && !$run->active_server_id && in_array($run->backup_result, ['success', 'failed'], true) && $canManage)
                            <div style="margin-bottom: 1rem">
                                {{ ($this->clearMissingHistoryAction)(['run' => $run->id]) }}
                            </div>
                        @endif
                        <details wire:key="backup-details-{{ $run->id }}">
                            <summary style="cursor: pointer; font-size: .875rem">Show run details</summary>
                            <div style="margin-top: .75rem; overflow-wrap: anywhere; font-size: .875rem">
                                <p>Original state: {{ $run->original_state ?? 'unknown' }} · Backup ID: {{ $run->backup_id ?? '—' }} · UUID: {{ $run->backup_uuid ?? '—' }}</p>
                                <ul>@foreach($run->events ?? [] as $event)<li>{{ $event['at'] }} — {{ $event['message'] }}</li>@endforeach</ul>
                            </div>
                        </details>
                    </article>
                @empty
                    <p>No backup runs yet.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
