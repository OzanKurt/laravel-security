<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OzanKurt\Shield\Support\Migrations\ShieldBlueprint;

return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('shield.database.connection');
    }

    public function up(): void
    {
        Schema::create('ls_integrity_runs', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('trigger_id');
            $table->unsignedBigInteger('severity_id')->nullable();
            $table->string('disk');
            $table->string('scope_fingerprint')->nullable();
            $table->string('baseline_root_hash')->nullable();
            $table->string('current_root_hash')->nullable();
            $table->integer('files_total')->default(0);
            $table->integer('files_hashed')->default(0);
            $table->integer('files_size_only')->default(0);
            $table->integer('files_unreadable')->default(0);
            $table->integer('files_excluded')->default(0);
            $table->integer('count_new')->default(0);
            $table->integer('count_modified')->default(0);
            $table->integer('count_deleted')->default(0);
            $table->integer('count_scope_changed')->default(0);
            $table->integer('count_vs_known_good')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            ShieldBlueprint::applyStandard($table);

            $table->index('status_id');
            $table->index('disk');
            $table->foreign('status_id')->references('id')->on('ls_integrity_statuses');
            $table->foreign('trigger_id')->references('id')->on('ls_scanner_triggers');
            $table->foreign('severity_id')->references('id')->on('ls_log_levels');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ls_integrity_runs');
    }
};
