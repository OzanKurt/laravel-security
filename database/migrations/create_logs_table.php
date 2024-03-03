<?php

use OzanKurt\Security\Enums\LogLevel;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected string $tableName;

    public function __construct()
    {
        $this->connection = config('security.database.connection');
        $this->tableName = config('security.database.table_prefix') . config('security.database.log.table');
    }

    public function up(): void
    {
        Schema::create($this->tableName, function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('user_id')->nullable()->index();
            $table->string('middleware')->index();
            $table->string('level')->default(LogLevel::MEDIUM)->index();
            $table->string('ip')->index();
            $table->text('url')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referrer')->nullable();
            $table->json('request_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::drop($this->tableName);
    }
};
