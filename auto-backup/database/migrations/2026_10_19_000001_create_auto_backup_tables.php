<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('auto_backup_configs')) { Schema::create('auto_backup_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('user_id');
            $table->boolean('enabled')->default(false);
            $table->json('settings');
            $table->dateTime('next_due_at')->nullable()->index();
            $table->timestamps();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        }); }
        if (!Schema::hasTable('auto_backup_runs')) { Schema::create('auto_backup_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            // NULL for completed runs; unique, persistent reservation for active/uncertain runs.
            $table->unsignedInteger('active_server_id')->nullable()->unique();
            $table->unsignedInteger('user_id');
            $table->string('occurrence')->unique();
            $table->json('settings');
            $table->string('state')->default('checking');
            $table->dateTime('scheduled_at');
            $table->dateTime('wake_at')->index();
            $table->dateTime('deadline_at')->nullable();
            $table->string('original_state')->nullable();
            $table->boolean('stopped_by_plugin')->default(false);
            $table->dateTime('stop_requested_at')->nullable();
            $table->unsignedInteger('backup_id')->nullable()->index();
            $table->string('backup_uuid')->nullable();
            $table->string('backup_name');
            $table->string('backup_result')->default('pending');
            $table->string('rotation_result')->default('pending');
            $table->string('restart_result')->default('pending');
            $table->json('context')->nullable();
            $table->json('events')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        }); }
    }

    // Uninstall keeps history and backup ownership. Explicit removal is documented in README.
    public function down(): void {}
};
