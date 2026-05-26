<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'ls_acl_kinds',
        'ls_acl_actions',
        'ls_log_levels',
        'ls_log_kinds',
        'ls_auth_event_kinds',
        'ls_audit_log_kinds',
        'ls_action_kinds',
        'ls_waf_rule_categories',
        'ls_waf_rule_kinds',
        'ls_waf_rule_targets',
        'ls_waf_rule_actions',
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
