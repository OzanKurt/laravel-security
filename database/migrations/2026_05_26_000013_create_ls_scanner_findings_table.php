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
        Schema::create('ls_scanner_findings', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);
            $table->unsignedBigInteger('scanner_run_id');
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('backend_id');
            $table->unsignedBigInteger('signature_id')->nullable();
            $table->string('signature_ref', 255)->nullable();
            $table->unsignedBigInteger('severity_id');
            $table->unsignedBigInteger('status_id');
            $table->text('file_path');
            $table->char('file_hash', 64)->nullable();
            $table->integer('line_number')->nullable();
            $table->text('matched_content')->nullable();
            $table->text('notes')->nullable();
            $table->text('quarantine_path')->nullable();
            ShieldBlueprint::applyStandard($table);

            $table->index('scanner_run_id');
            $table->index(['target_id', 'status_id']);
            $table->foreign('scanner_run_id')->references('id')->on('ls_scanner_runs');
            $table->foreign('target_id')->references('id')->on('ls_scanner_targets');
            $table->foreign('backend_id')->references('id')->on('ls_scanner_backends');
            $table->foreign('signature_id')->references('id')->on('ls_signatures');
            $table->foreign('severity_id')->references('id')->on('ls_log_levels');
            $table->foreign('status_id')->references('id')->on('ls_scanner_finding_statuses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ls_scanner_findings');
    }
};
