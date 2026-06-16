<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('shield.database.connection');
    }

    public function up(): void
    {
        $prefix = config('shield.database.table_prefix', 'security_');

        Schema::dropIfExists($prefix . 'logs');
        Schema::dropIfExists($prefix . 'ips');
        Schema::dropIfExists($prefix . 'auth_logs');
    }

    public function down(): void
    {
        // No reverse, these tables are obsolete in v1.0
    }
};
