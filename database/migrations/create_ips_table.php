<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('security.database.connection'))->create(config('security.database.ip.table'), function (Blueprint $table) {
            $table->id();
            $table->string('ip')->index('ip');
            $table->foreignId('log_id')->nullable()->index();
            $table->boolean('is_blocked')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection(config('security.database.connection'))->drop(config('security.database.ip.table'));
    }
};
