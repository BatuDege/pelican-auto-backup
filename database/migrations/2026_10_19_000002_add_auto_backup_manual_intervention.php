<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('auto_backup_runs', 'manual_intervention_at')) { return; }
        Schema::table('auto_backup_runs', function (Blueprint $table) {
            $table->dateTime('manual_intervention_at')->nullable();
        });
    }
    public function down(): void {}
};
