<?php

namespace Pelican\AutoBackup\Contracts;

use Pelican\AutoBackup\Models\BackupRun;

interface PanelGateway
{
    public function authorize(int $server, int $user, array $settings): void;
    public function preflight(BackupRun $run): void;
    public function status(BackupRun $run): string;
    public function command(BackupRun $run, string $command): void;
    public function power(BackupRun $run, string $action): void;
    public function create(BackupRun $run): void;
    public function backupState(BackupRun $run): string;
    public function rotate(BackupRun $run): array;
    public function manuallyControlled(BackupRun $run): bool;
    public function serverName(int $server): string;
    public function hasPendingBackup(int $server): bool;
}
