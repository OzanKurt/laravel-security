<?php

use OzanKurt\Security\Enums\LogLevel;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('security.database.connection'))->create(config('security.database.log.table'), function (Blueprint $table) {
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
        Schema::connection(config('security.database.connection'))->drop(config('security.database.log.table'));
    }
};
