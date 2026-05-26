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
        Schema::create('ls_live_traffic', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);
            $table->string('ip', 45)->index();
            $table->char('country_code', 2)->nullable()->index();
            $table->string('asn', 16)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('method', 8);
            $table->text('url');
            $table->smallInteger('status_code')->index();
            $table->integer('response_time_ms')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referrer', 255)->nullable();
            $table->string('bot_identity', 64)->nullable();
            $table->unsignedBigInteger('action_taken_id')->nullable();
            $table->unsignedBigInteger('matched_rule_id')->nullable();
            $table->char('fingerprint_hash', 32)->nullable()->index();
            $table->uuid('uuid')->unique();
            $table->timestamps();
            $table->index('created_at');
            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ls_live_traffic');
    }
};
