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
        Schema::create('ls_acl', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);
            $table->unsignedBigInteger('kind_id');
            $table->string('value', 255);
            $table->unsignedBigInteger('action_id');
            $table->string('source', 64)->default('manual');
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('log_id')->nullable();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            ShieldBlueprint::applyStandard($table);

            $table->index(['kind_id', 'value']);
            $table->index(['action_id', 'expires_at']);
            $table->index('source');

            $table->foreign('kind_id')->references('id')->on('ls_acl_kinds');
            $table->foreign('action_id')->references('id')->on('ls_acl_actions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ls_acl');
    }
};
