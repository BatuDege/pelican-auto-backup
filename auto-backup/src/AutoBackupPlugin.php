<?php

namespace Pelican\AutoBackup;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Pelican\AutoBackup\Filament\Pages\AutoBackup;

class AutoBackupPlugin implements Plugin
{
    public function getId(): string { return 'auto-backup'; }
    public function register(Panel $panel): void { $panel->pages([AutoBackup::class]); }
    public function boot(Panel $panel): void {}
}
