<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OzanKurt\Shield\Support\Migrations\ShieldBlueprint;

/**
 * Outbound webhook delivery log. Every call CentralClient makes against
 * the Central app (webhook ingest, heartbeat, test ping) writes one row
 * here so operators can inspect what was sent, what came back, and
 * retry permanent failures from the dashboard.
 *
 * This table is the on-site equivalent of Stripe's "Webhook attempt"
 * timeline — without it, an operator faced with "events aren't reaching
 * Central" has no way to tell whether the plugin sent + got rejected,
 * sent + got nothing, or never sent at all.
 */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('shield.database.connection');
    }

    public function up(): void
    {
        Schema::create('ls_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);

            // What was sent + where
            $table->string('operation', 32)->index();         // webhook_ingest | heartbeat | test_ping | webhook_ingest_batch
            $table->string('target_url', 255);
            $table->string('payload_hash', 64)->index();      // sha256 of the raw signed body
            $table->unsignedSmallInteger('payload_bytes')->default(0);
            $table->unsignedSmallInteger('batch_size')->default(1);

            // Outcome
            $table->string('status', 16)->index();            // pending | success | failure | skipped | exhausted
            $table->string('reason', 64)->nullable();         // skipped reason / failure category
            $table->unsignedSmallInteger('http_status')->default(0);
            $table->string('response_excerpt', 512)->nullable();

            // Retry tracking — populated by the queued job's middleware
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->unsignedTinyInteger('max_attempts')->default(3);

            // Timing — start vs completion for latency analytics
            $table->timestamp('dispatched_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Source linkage — when this is forwarding a specific audit
            // log row, store the local id for cross-reference
            $table->unsignedBigInteger('audit_log_id')->nullable()->index();

            ShieldBlueprint::applyStandard($table);

            $table->index(['status', 'operation']);
            $table->index(['dispatched_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ls_webhook_deliveries');
    }
};
