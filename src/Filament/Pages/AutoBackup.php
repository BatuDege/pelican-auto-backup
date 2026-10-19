<?php

namespace Pelican\AutoBackup\Filament\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Filament\Server\Pages\ServerFormPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Pelican\AutoBackup\Jobs\AdvanceBackup;
use Pelican\AutoBackup\Models\BackupConfig;
use Pelican\AutoBackup\Models\BackupRun;
use Pelican\AutoBackup\Services\RunManager;
use Pelican\AutoBackup\Services\Settings;
use Pelican\AutoBackup\Services\ScheduleInput;
use Pelican\AutoBackup\Services\ManageBackups;
use Pelican\AutoBackup\Services\ClearFailedHistory;
use Pelican\AutoBackup\Services\ClearMissingHistory;
use Throwable;

class AutoBackup extends ServerFormPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Clock;
    protected static ?int $navigationSort = 35;
    protected static ?string $navigationLabel = 'Auto Backup';
    protected static ?string $title = 'Auto Backup';
    protected static ?string $slug = 'auto-backup';
    protected string $view = 'auto-backup::auto-backup';

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();
        return $server && user()?->can(SubuserPermission::BackupRead, $server)
            && user()?->can(SubuserPermission::ScheduleRead, $server);
    }

    protected function authorizeAccess(): void { abort_unless(static::canAccess(), 403); }

    public function getHeaderActionsAlignment(): ?Alignment { return Alignment::End; }

    protected function fillForm(): void
    {
        $config = BackupConfig::where('server_id', $this->getRecord()->id)->first();
        $settings = $config?->settings ?? Settings::defaults();
        $this->form->fill(['enabled' => $config?->enabled ?? false, ...$settings, ...ScheduleInput::fromCron($settings['cron'])]);
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->components([
            Section::make('Schedule and mode')->description('The scheduled time marks the start of preparation. Warnings are sent beforehand.')->columns(2)->schema([
                Toggle::make('enabled')->label('Enable automatic backups')->columnSpanFull(),
                Select::make('schedule_type')->label('Repeat')->options([
                    'daily' => 'Daily', 'weekly' => 'Weekly', 'interval' => 'Every X hours', 'custom' => 'Advanced: custom cron expression',
                ])->required()->live()->native(false),
                Select::make('timezone')->label('Timezone')->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->searchable()->required(),
                TimePicker::make('schedule_time')->label('Time')->seconds(false)->format('H:i')->required()
                    ->visible(fn (Get $get) => in_array($get('schedule_type'), ['daily', 'weekly'], true)),
                Select::make('schedule_day')->label('Day of the week')->options([
                    '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '0' => 'Sunday',
                ])->required()->visible(fn (Get $get) => $get('schedule_type') === 'weekly'),
                Select::make('schedule_interval')->label('Interval')->options([
                    '1' => 'Every hour', '2' => 'Every 2 hours', '3' => 'Every 3 hours', '4' => 'Every 4 hours',
                    '6' => 'Every 6 hours', '8' => 'Every 8 hours', '12' => 'Every 12 hours',
                ])->required()->helperText('Hours are counted from midnight; for example, every 6 hours runs at 00:00, 06:00, 12:00 and 18:00.')
                    ->visible(fn (Get $get) => $get('schedule_type') === 'interval'),
                Select::make('schedule_minute')->label('Minute of the hour')
                    ->options(collect(range(0, 59))->mapWithKeys(fn ($minute) => [(string) $minute => sprintf(':%02d', $minute)])->all())
                    ->required()->visible(fn (Get $get) => $get('schedule_type') === 'interval'),
                TextInput::make('cron')->label('Cron schedule')->required()->helperText('For custom schedules only; for example, 0 4 * * 1-5 runs on weekdays at 04:00.')
                    ->maxLength(100)->columnSpanFull()->visible(fn (Get $get) => $get('schedule_type') === 'custom'),
                Toggle::make('live')->label('Allow live backups')->helperText('When enabled, the server keeps running during the backup; consistency depends on the game.')->columnSpanFull(),
                TextInput::make('name')->label('Backup name')->required()->maxLength(150)->helperText('{date}, {server}')->columnSpanFull(),
                TextInput::make('retention')->label('Auto backups to keep')->numeric()->required()->minValue(1)->maxValue(1000)
                    ->helperText('Locked auto backups count toward retention but are never deleted. Manual backups are preserved. Allow one extra slot for the next backup.'),
                TextInput::make('backup_timeout')->label('Backup timeout (seconds)')->numeric()->required()->minValue(60)->maxValue(86400)
                    ->helperText('Default: ' . Settings::defaults()['backup_timeout'] . ' seconds (2 hours).'),
            ]),
            Section::make('Warnings and preparation')->description('Enter console commands without a leading slash unless the game requires one. {time} = seconds remaining; also supports {server}, {date}, {backup_name}.')->schema([
                Repeater::make('warnings')->label('Warnings before offline backups')->schema([
                    TextInput::make('seconds')->label('Seconds before')->numeric()->minValue(1)->maxValue(3600)->required(),
                    TextInput::make('command')->label('Command')->required()->maxLength(1000),
                ])->columns(2)->defaultItems(0)->maxItems(20)->addActionLabel('Add warning'),
                Repeater::make('commands')->label('Pre-backup commands (in order)')->schema([
                    TextInput::make('command')->label('Command')->required()->maxLength(1000),
                ])->defaultItems(0)->maxItems(20)->addActionLabel('Add command'),
                TextInput::make('pre_delay')->label('Delay after commands (seconds)')->numeric()->required()->minValue(0)->maxValue(300)
                    ->helperText('Default: ' . Settings::defaults()['pre_delay'] . ' seconds.'),
            ]),
            Section::make('Shutdown and restart')->columns(2)->schema([
                TextInput::make('shutdown_timeout')->label('Shutdown / status timeout (seconds)')->numeric()->required()->minValue(10)->maxValue(1800)
                    ->helperText('Default: ' . Settings::defaults()['shutdown_timeout'] . ' seconds (2 minutes).'),
                TextInput::make('start_timeout')->label('Start timeout (seconds)')->numeric()->required()->minValue(10)->maxValue(1800)
                    ->helperText('Default: ' . Settings::defaults()['start_timeout'] . ' seconds (3 minutes).'),
                Toggle::make('restart_success')->label('Start after a successful backup'),
                Toggle::make('restart_failure')->label('Start after a failed backup'),
            ]),
        ]);
    }

    public function save(): void
    {
        $this->authorizeAccess();
        $data = $this->form->getState();
        $enabled = (bool) $data['enabled'];
        unset($data['enabled']);
        try {
            $data['cron'] = ScheduleInput::toCron($data);
            unset($data['schedule_type'], $data['schedule_time'], $data['schedule_day'], $data['schedule_interval'], $data['schedule_minute']);
            app(RunManager::class)->save($this->getRecord()->id, user()->id, $enabled, $data);
            Notification::make()->title('Auto Backup settings saved')->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Could not save settings')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveSettings')->label('Save settings')->icon(TablerIcon::DeviceFloppy)->button()
                ->submit('save')->formId('auto-backup-settings'),
            Action::make('runNow')->label('Run now')->icon(TablerIcon::PlayerPlay)->button()->color('gray')
                ->requiresConfirmation()->modalDescription('Runs with the saved settings, including warnings. In offline mode, a running server will be stopped.')
                ->action(function () {
                    $this->authorizeAccess();
                    try {
                        if (config('queue.default') === 'sync') { throw new \RuntimeException('An asynchronous queue is required.'); }
                        $config = BackupConfig::where('server_id', $this->getRecord()->id)->firstOrFail();
                        $config->user_id = user()->id;
                        $run = app(RunManager::class)->create($config, manual: true);
                        AdvanceBackup::dispatch($run->id);
                        Notification::make()->title('Backup run queued')->success()->send();
                    } catch (UniqueConstraintViolationException) {
                        Notification::make()->title('A run is already active for this server')->warning()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Could not start the run')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('acknowledge')->label('Release unresolved run')->color('warning')
                ->visible(fn () => BackupRun::where('active_server_id', $this->getRecord()->id)->where('state', 'attention')->exists())
                ->requiresConfirmation()->modalDescription('First check on Wings that no backup is still running and resolve the server state manually. This confirmation only releases the run reservation; it does not start the server or retry any action.')
                ->action(function () {
                    $this->authorizeAccess();
                    try {
                        $run = BackupRun::where('active_server_id', $this->getRecord()->id)->where('state', 'attention')->firstOrFail();
                        app(RunManager::class)->acknowledge($run, user()->id);
                        Notification::make()->title('Run released')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Could not release the run')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    public function lockBackupAction(): Action
    {
        return Action::make('lockBackup')->label('Lock')->icon(TablerIcon::Lock)->color('gray')->button()->size('sm')
            ->action(fn (array $arguments) => $this->manageBackup('lock', $arguments));
    }

    public function clearFailedHistoryAction(): Action
    {
        return Action::make('clearFailedHistory')->label('Clear failed entries')
            ->icon(TablerIcon::Trash)->button()->color('gray')->size('sm')
            ->visible(fn () => user()?->can(SubuserPermission::BackupDelete, $this->getRecord())
                && user()?->can(SubuserPermission::ScheduleUpdate, $this->getRecord()))
            ->requiresConfirmation()->modalHeading('Clear all completed failed entries?')
            ->modalDescription('Hides all completed failed runs for this server from the history, including older entries. Backup files, successful backups, active runs and unresolved runs are preserved.')
            ->modalSubmitActionLabel('Clear failed entries')
            ->action(function () {
                $this->authorizeAccess();
                try {
                    $count = app(ClearFailedHistory::class)->handle($this->getRecord(), user());
                    Notification::make()->title($count === 0 ? 'No completed failed entries to clear' : "$count failed entries cleared from history")
                        ->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Could not clear history')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public function unlockBackupAction(): Action
    {
        return Action::make('unlockBackup')->label('Unlock')->icon(TablerIcon::LockOpen)->color('gray')->button()->size('sm')
            ->requiresConfirmation()->modalHeading('Unlock this backup?')
            ->modalDescription('Once unlocked, this backup can be deleted by the automatic retention policy.')
            ->action(fn (array $arguments) => $this->manageBackup('unlock', $arguments));
    }

    public function clearMissingHistoryAction(): Action
    {
        return Action::make('clearMissingHistory')->label('Remove history entry')
            ->icon(TablerIcon::Trash)->button()->color('gray')->size('sm')
            ->visible(fn () => user()?->can(SubuserPermission::BackupDelete, $this->getRecord())
                && user()?->can(SubuserPermission::ScheduleUpdate, $this->getRecord()))
            ->requiresConfirmation()->modalHeading('Remove the deleted backup entry?')
            ->modalDescription('The backup has already been deleted. Only this card will be removed from the displayed history.')
            ->modalSubmitActionLabel('Remove history entry')
            ->action(function (array $arguments) {
                $this->authorizeAccess();
                try {
                    $id = filter_var($arguments['run'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    abort_unless($id, 404);
                    app(ClearMissingHistory::class)->handle($this->getRecord(), user(), $id);
                    Notification::make()->title('History entry removed')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Could not remove the entry')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public function deleteBackupAction(): Action
    {
        return Action::make('deleteBackup')->label('Delete')->icon(TablerIcon::Trash)->color('danger')->button()->size('sm')
            ->requiresConfirmation()->modalHeading('Permanently delete this backup?')
            ->modalDescription('Pelican will delete the backup file from storage. The run history entry will remain.')
            ->modalSubmitActionLabel('Delete backup')
            ->action(fn (array $arguments) => $this->manageBackup('delete', $arguments));
    }

    protected function manageBackup(string $operation, array $arguments): void
    {
        $this->authorizeAccess();
        try {
            $id = filter_var($arguments['backup'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless($id, 404);
            $service = app(ManageBackups::class);
            if ($operation === 'delete') {
                $service->delete($this->getRecord(), user(), $id);
            } else {
                $service->setLocked($this->getRecord(), user(), $id, $operation === 'lock');
            }
            Notification::make()->title(match ($operation) {
                'delete' => 'Backup deleted', 'lock' => 'Backup locked', default => 'Backup unlocked',
            })->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Could not update the backup')->body($e->getMessage())->danger()->send();
        }
    }

    public function overview(): array
    {
        $this->authorizeAccess();
        return [
            'config' => BackupConfig::where('server_id', $this->getRecord()->id)->first(),
            'active' => BackupRun::where('active_server_id', $this->getRecord()->id)->first(),
            'runs' => BackupRun::where('server_id', $this->getRecord()->id)
                ->whereNull('history_cleared_at')
                ->with(['backup' => fn ($query) => $query->where('server_id', $this->getRecord()->id)])
                ->latest('id')->limit(30)->get(),
        ];
    }
}
