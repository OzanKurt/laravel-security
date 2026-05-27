<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Lookups\ScannerFindingStatus;
use OzanKurt\Shield\Models\ScannerFinding;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class QuarantineListCommand extends Command
{
    protected $signature = 'shield:quarantine-list';
    protected $description = 'List all findings currently in quarantine.';

    public function handle(LookupResolver $lookups): int
    {
        $quarantinedId = $lookups->id(ScannerFindingStatus::class, 'quarantined');

        $rows = ScannerFinding::query()
            ->where('status_id', $quarantinedId)
            ->latest('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No findings in quarantine.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'UUID', 'Original path', 'Vault path', 'Updated'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->uuid,
                $r->file_path,
                $r->quarantine_path,
                (string) $r->updated_at,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
