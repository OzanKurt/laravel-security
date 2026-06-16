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
        Schema::create('ls_integrity_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('scope_fingerprint')->nullable();
            $table->string('root_hash')->nullable();
            $table->string('artifact_path')->nullable();
            $table->string('algo', 32)->default('sha256');
            $table->integer('files_total')->default(0);
            $table->boolean('signed')->default(false);
            $table->timestamp('blessed_at')->nullable();
            ShieldBlueprint::applyStandard($table);

            $table->index('disk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ls_integrity_baselines');
    }
};
