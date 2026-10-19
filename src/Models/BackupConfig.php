<?php

namespace Pelican\AutoBackup\Models;

use Illuminate\Database\Eloquent\Model;

class BackupConfig extends Model
{
    protected $table = 'auto_backup_configs';
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['settings' => 'array', 'enabled' => 'boolean', 'next_due_at' => 'immutable_datetime'];
    }
}
