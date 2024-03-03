<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected string $tableName;

    public function __construct()
    {
        $this->connection = config('security.database.connection');
        $this->tableName = config('security.database.table_prefix') . config('security.database.ip.table');
    }

    public function up(): void
    {
        Schema::create($this->tableName, function (Blueprint $table) {
            $table->id();
            $table->string('ip')->index();
            $table->foreignId('log_id')->nullable()->index();
            $table->boolean('is_blocked')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::drop($this->tableName);
    }
};
