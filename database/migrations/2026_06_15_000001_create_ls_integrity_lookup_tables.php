<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'ls_integrity_statuses',
        'ls_integrity_change_types',
        'ls_integrity_comparison_bases',
    ];

    public function __construct()
    {
        $this->connection = config('shield.database.connection');
    }

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('name', 64)->unique();
                $table->string('label', 128);
                $table->text('description')->nullable();
                $table->integer('sort_order')->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index('sort_order');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
