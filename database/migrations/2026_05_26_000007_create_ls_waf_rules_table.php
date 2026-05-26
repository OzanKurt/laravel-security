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
        Schema::create('ls_waf_rules', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);
            $table->string('source', 64)->default('builtin');
            $table->string('source_ref', 255)->nullable();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('kind_id');
            $table->unsignedBigInteger('target_id');
            $table->text('pattern');
            $table->unsignedBigInteger('action_id');
            $table->unsignedBigInteger('severity_id');
            $table->smallInteger('score')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->json('meta')->nullable();
            $table->integer('version')->default(1);
            ShieldBlueprint::applyStandard($table);

            $table->index(['category_id', 'is_enabled']);
            $table->unique(['source', 'source_ref']);

            $table->foreign('category_id')->references('id')->on('ls_waf_rule_categories');
            $table->foreign('kind_id')->references('id')->on('ls_waf_rule_kinds');
            $table->foreign('target_id')->references('id')->on('ls_waf_rule_targets');
            $table->foreign('action_id')->references('id')->on('ls_waf_rule_actions');
            $table->foreign('severity_id')->references('id')->on('ls_log_levels');
        });
    }

    public function down(): void { Schema::dropIfExists('ls_waf_rules'); }
};
