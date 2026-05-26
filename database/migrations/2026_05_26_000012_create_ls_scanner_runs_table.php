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
        Schema::create('ls_scanner_runs', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);
            $table->unsignedBigInteger('trigger_id');
            $table->unsignedBigInteger('status_id');
            $table->json('targets')->nullable();
            $table->json('backends')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('files_scanned')->default(0);
            $table->integer('findings_count')->default(0);
            $table->integer('findings_critical_count')->default(0);
            $table->text('error_message')->nullable();
            ShieldBlueprint::applyStandard($table);

            $table->index('status_id');
            $table->foreign('trigger_id')->references('id')->on('ls_scanner_triggers');
            $table->foreign('status_id')->references('id')->on('ls_scanner_statuses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ls_scanner_runs');
    }
};
