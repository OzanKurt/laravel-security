<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen ls_webhook_deliveries.payload_bytes from SMALLINT (max 65 535)
 * to INT (max 4.2B). A single audit event's serialized payload routinely
 * exceeds 64 KiB once you include stack-trace metas / change diffs, and
 * batch ingest pushes can be megabytes. The previous SMALLINT silently
 * threw on strict-mode MySQL (lost dashboard row) or clamped on lax mode
 * (wrong byte count in dashboard).
 *
 * batch_size already maxes at the controller-side limit (500), so SMALLINT
 * stays fine there, no widening needed for that column.
 */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('shield.database.connection');
    }

    public function up(): void
    {
        // Laravel 11+ no longer needs doctrine/dbal for ->change() but
        // this package still supports L9/L10. Use raw ALTER per driver
        // for portability, and to skip the doctrine requirement.
        $driver = DB::connection($this->connection)->getDriverName();

        match ($driver) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE ls_webhook_deliveries MODIFY payload_bytes INT UNSIGNED NOT NULL DEFAULT 0'),
            'pgsql' => DB::statement('ALTER TABLE ls_webhook_deliveries ALTER COLUMN payload_bytes TYPE INTEGER'),
            'sqlite' => null, // SQLite is dynamic-typed; SMALLINT is just affinity. No-op.
            default => null,
        };
    }

    public function down(): void
    {
        $driver = DB::connection($this->connection)->getDriverName();

        match ($driver) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE ls_webhook_deliveries MODIFY payload_bytes SMALLINT UNSIGNED NOT NULL DEFAULT 0'),
            'pgsql' => DB::statement('ALTER TABLE ls_webhook_deliveries ALTER COLUMN payload_bytes TYPE SMALLINT'),
            'sqlite' => null,
            default => null,
        };
    }
};
