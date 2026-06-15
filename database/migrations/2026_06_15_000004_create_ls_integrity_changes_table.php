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
        Schema::create('ls_integrity_changes', function (Blueprint $table) {
            $table->id();
            ShieldBlueprint::applyCorrelationId($table);
            $table->unsignedBigInteger('integrity_run_id');
            $table->unsignedBigInteger('change_type_id');
            $table->unsignedBigInteger('compared_to_id');
            $table->unsignedBigInteger('severity_id')->nullable();
            $table->string('path', 1024);
            $table->string('old_hash', 128)->nullable();
            $table->string('new_hash', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('file_mtime')->nullable();
            $table->string('symlink_target', 1024)->nullable();
            ShieldBlueprint::applyStandard($table);

            $table->index('integrity_run_id');
            $table->index('change_type_id');
            $table->index('compared_to_id');
            $table->index('severity_id');
            $table->foreign('integrity_run_id')->references('id')->on('ls_integrity_runs');
            $table->foreign('change_type_id')->references('id')->on('ls_integrity_change_types');
            $table->foreign('compared_to_id')->references('id')->on('ls_integrity_comparison_bases');
            $table->foreign('severity_id')->references('id')->on('ls_log_levels');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ls_integrity_changes');
    }
};
