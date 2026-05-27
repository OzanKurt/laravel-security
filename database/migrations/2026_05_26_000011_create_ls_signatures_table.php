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
        Schema::create('ls_signatures', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);
            $table->string('source', 64)->default('builtin_native');
            $table->string('source_ref', 255)->nullable();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->string('kind', 32)->default('regex'); // 'regex', 'file_hash', 'string_match'
            $table->text('pattern');
            $table->unsignedBigInteger('severity_id');
            $table->integer('version')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->json('meta')->nullable();
            ShieldBlueprint::applyStandard($table);

            $table->unique(['source', 'source_ref']);
            $table->index(['category_id', 'is_enabled']);
            $table->foreign('category_id')->references('id')->on('ls_signature_categories');
            $table->foreign('severity_id')->references('id')->on('ls_log_levels');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ls_signatures');
    }
};
