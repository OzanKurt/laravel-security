<?php

namespace OzanKurt\Shield\Tests\Feature\Integrity;

use Illuminate\Support\Carbon;
use OzanKurt\Shield\Models\IntegrityBaseline;
use OzanKurt\Shield\Models\IntegrityChange;
use OzanKurt\Shield\Models\IntegrityRun;
use OzanKurt\Shield\Models\Lookups\IntegrityChangeType;
use OzanKurt\Shield\Models\Lookups\IntegrityComparisonBasis;
use OzanKurt\Shield\Models\Lookups\IntegrityStatus;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\ScannerTrigger;
use OzanKurt\Shield\Tests\TestCase;

class IntegrityDataLayerTest extends TestCase
{
    public function test_integrity_lookups_are_seeded(): void
    {
        $this->assertSame(10, IntegrityStatus::count());
        $this->assertSame(8, IntegrityChangeType::count());
        $this->assertSame(2, IntegrityComparisonBasis::count());

        $this->assertNotNull(IntegrityStatus::where('name', 'baseline_established')->first());
        $this->assertNotNull(IntegrityChangeType::where('name', 'scope_changed')->first());
        $this->assertNotNull(IntegrityComparisonBasis::where('name', 'known_good')->first());
    }

    public function test_integrity_run_persists_with_uuid_and_resolves_lookups(): void
    {
        $run = IntegrityRun::create([
            'status_id' => IntegrityStatus::where('name', 'completed')->value('id'),
            'trigger_id' => ScannerTrigger::where('name', 'scheduled')->value('id'),
            'severity_id' => LogLevel::where('name', 'high')->value('id'),
            'disk' => 'app',
            'files_total' => 92106,
            'count_new' => 8,
            'count_modified' => 2,
            'count_deleted' => 0,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $fresh = $run->fresh();

        $this->assertNotEmpty($fresh->uuid);
        $this->assertSame('completed', $fresh->status->name);
        $this->assertSame('scheduled', $fresh->trigger->name);
        $this->assertSame('high', $fresh->severity->name);
        $this->assertSame(92106, $fresh->files_total);
    }

    public function test_integrity_change_links_to_run_and_lookups(): void
    {
        $run = IntegrityRun::create([
            'status_id' => IntegrityStatus::where('name', 'completed')->value('id'),
            'trigger_id' => ScannerTrigger::where('name', 'scheduled')->value('id'),
            'disk' => 'app',
        ]);

        $change = IntegrityChange::create([
            'integrity_run_id' => $run->id,
            'change_type_id' => IntegrityChangeType::where('name', 'new')->value('id'),
            'compared_to_id' => IntegrityComparisonBasis::where('name', 'last_run')->value('id'),
            'severity_id' => LogLevel::where('name', 'critical')->value('id'),
            'path' => 'public/cache/ff/3776/footer.php',
            'new_hash' => str_repeat('a', 64),
            'size_bytes' => 1234,
        ]);

        $this->assertNotEmpty($change->uuid);
        $this->assertSame($run->id, $change->run->id);
        $this->assertSame('new', $change->changeType->name);
        $this->assertSame('last_run', $change->comparedTo->name);
        $this->assertSame('critical', $change->severity->name);
        $this->assertSame(1, $run->changes()->count());
    }

    public function test_integrity_baseline_casts_and_uuid(): void
    {
        $baseline = IntegrityBaseline::create([
            'disk' => 'app',
            'scope_fingerprint' => 'abc123',
            'root_hash' => str_repeat('b', 64),
            'artifact_path' => 'shield/integrity/app/baseline.ndjson.gz',
            'files_total' => 92106,
            'signed' => true,
            'blessed_at' => now(),
        ]);

        $fresh = $baseline->fresh();

        $this->assertNotEmpty($fresh->uuid);
        $this->assertTrue($fresh->signed);
        $this->assertSame(92106, $fresh->files_total);
        $this->assertInstanceOf(Carbon::class, $fresh->blessed_at);
    }
}
