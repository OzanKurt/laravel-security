<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OzanKurt\Shield\Support\Migrations\ShieldBlueprint;

return new class extends Migration
{
    public function __construct() { $this->connection = config('shield.database.connection'); }

    public function up(): void
    {
        Schema::create('ls_audit_log', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);
            $table->unsignedBigInteger('kind_id');
            $table->unsignedBigInteger('severity_id');
            $table->string('actor_type', 64)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('url')->nullable();
            $table->text('description');
            $table->json('changes')->nullable();
            $table->json('meta')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hmac', 64);
            ShieldBlueprint::applyStandard($table);

            $table->index('kind_id');
            $table->index('severity_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_type', 'actor_id']);

            $table->foreign('kind_id')->references('id')->on('ls_audit_log_kinds');
            $table->foreign('severity_id')->references('id')->on('ls_log_levels');
        });
    }

    public function down(): void { Schema::dropIfExists('ls_audit_log'); }
};
