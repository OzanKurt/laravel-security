<?php

namespace OzanKurt\Shield\Tests\Feature\Integrity;

use Illuminate\Support\Facades\Event;
use OzanKurt\Shield\Events\IntegrityScanCompletedEvent;
use OzanKurt\Shield\Models\IntegrityBaseline;
use OzanKurt\Shield\Models\IntegrityChange;
use OzanKurt\Shield\Models\Lookups\IntegrityChangeType;
use OzanKurt\Shield\Services\Integrity\IntegrityScanner;
use OzanKurt\Shield\Tests\TestCase;

class IntegrityScannerTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();

        $this->src = sys_get_temp_dir() . '/shield_scan_src_' . uniqid();
        @mkdir($this->src . '/app', 0777, true);
        @mkdir($this->src . '/public/cache', 0777, true);
        file_put_contents($this->src . '/app/Service.php', '<?php // v1');
        file_put_contents($this->src . '/readme.md', 'hello world');

        config([
            'shield.integrity.baseline.hmac_key' => 'test-key',
            'shield.integrity.disks.test' => [
                'roots' => [$this->src],
                'key_base' => $this->src,
                'include' => ['**/*'],
                'exclude' => [],
                'follow_symlinks' => false,
                'max_file_size' => 50 * 1024 * 1024,
            ],
        ]);

        $this->cleanArtifacts();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->src);
        $this->cleanArtifacts();
        parent::tearDown();
    }

    private function scanner(): IntegrityScanner
    {
        return new IntegrityScanner();
    }

    public function test_first_run_establishes_provisional_unsigned_baseline(): void
    {
        $run = $this->scanner()->run('test', 'manual');

        $this->assertSame('baseline_established', $run->status->name);
        $this->assertGreaterThan(0, $run->files_total);
        $this->assertFileExists(storage_path('shield/integrity/test/baseline.ndjson.gz'));

        $baseline = IntegrityBaseline::where('disk', 'test')->first();
        $this->assertNotNull($baseline);
        $this->assertFalse($baseline->signed); // provisional, not yet trusted
    }

    public function test_second_run_detects_new_and_modified_files_and_fires_event(): void
    {
        Event::fake([IntegrityScanCompletedEvent::class]);

        $this->scanner()->run('test', 'manual'); // establish baseline

        file_put_contents($this->src . '/app/Service.php', '<?php // v2 changed and longer');
        file_put_contents($this->src . '/app/New.php', '<?php // brand new file');

        $run = $this->scanner()->run('test', 'scheduled');

        $this->assertSame('completed', $run->status->name);
        $this->assertSame(1, $run->count_new);
        $this->assertSame(1, $run->count_modified);

        $this->assertTrue(
            IntegrityChange::where('integrity_run_id', $run->id)->where('path', 'app/New.php')->exists()
        );

        Event::assertDispatched(IntegrityScanCompletedEvent::class);
    }

    public function test_new_php_under_public_is_critical(): void
    {
        $this->scanner()->run('test', 'manual'); // baseline

        file_put_contents($this->src . '/public/cache/footer.php', '<?php /* dropped webshell */');

        $run = $this->scanner()->run('test', 'manual');

        $this->assertSame('critical', $run->severity->name);

        $change = IntegrityChange::where('integrity_run_id', $run->id)
            ->where('path', 'public/cache/footer.php')
            ->first();
        $this->assertNotNull($change);
        $this->assertSame('critical', $change->severity->name);
    }

    public function test_tampered_baseline_is_flagged_and_not_reblessed(): void
    {
        $this->scanner()->run('test', 'manual'); // baseline + signature

        file_put_contents(storage_path('shield/integrity/test/baseline.ndjson.gz.sig'), 'deadbeef');

        $run = $this->scanner()->run('test', 'manual');

        $this->assertSame('tamper_suspected', $run->status->name);
        $this->assertSame('critical', $run->severity->name);
    }

    public function test_bless_signs_the_baseline(): void
    {
        $this->scanner()->run('test', 'manual'); // provisional, unsigned
        $baseline = $this->scanner()->bless('test');

        $this->assertTrue($baseline->signed);
        $this->assertSame('test', $baseline->disk);
        $this->assertSame(1, IntegrityBaseline::where('disk', 'test')->count()); // old row replaced
    }

    public function test_deleted_file_is_high_severity(): void
    {
        $this->scanner()->run('test', 'manual'); // baseline includes app/Service.php

        unlink($this->src . '/app/Service.php');

        $run = $this->scanner()->run('test', 'manual');

        $this->assertSame('high', $run->severity->name);

        $change = IntegrityChange::where('integrity_run_id', $run->id)
            ->where('path', 'app/Service.php')
            ->first();
        $this->assertNotNull($change);
        $this->assertSame('deleted', $change->changeType->name);
        $this->assertSame('high', $change->severity->name);
    }

    public function test_scope_change_is_classified_not_treated_as_deletion(): void
    {
        $this->scanner()->run('test', 'manual'); // baseline includes app/Service.php

        // Narrow the scope so app/** leaves the watched set.
        config(['shield.integrity.disks.test.exclude' => ['app/**']]);

        $run = $this->scanner()->run('test', 'manual');

        $this->assertGreaterThan(0, $run->count_scope_changed);

        $scopeChangedId = IntegrityChangeType::where('name', 'scope_changed')->value('id');
        $this->assertTrue(
            IntegrityChange::where('integrity_run_id', $run->id)
                ->where('change_type_id', $scopeChangedId)
                ->exists()
        );
    }

    public function test_missing_baseline_signature_fails_the_run(): void
    {
        $this->scanner()->run('test', 'manual'); // creates baseline + .sig

        unlink(storage_path('shield/integrity/test/baseline.ndjson.gz.sig'));

        $run = $this->scanner()->run('test', 'manual');

        $this->assertSame('failed', $run->status->name);
    }

    public function test_missing_last_run_artifact_does_not_duplicate_change_rows(): void
    {
        $this->scanner()->run('test', 'manual'); // writes baseline + last-run

        // Remove only the previous-run reference (baseline survives).
        @unlink(storage_path('shield/integrity/test/last-run.ndjson.gz'));
        @unlink(storage_path('shield/integrity/test/last-run.ndjson.gz.sig'));

        file_put_contents($this->src . '/app/New.php', '<?php // new');

        $run = $this->scanner()->run('test', 'manual');

        // Without a last-run, delta == drift; the change must be recorded once, not twice.
        $this->assertSame(1, IntegrityChange::where('integrity_run_id', $run->id)
            ->where('path', 'app/New.php')
            ->count());
    }

    private function cleanArtifacts(): void
    {
        $this->deleteTree(storage_path('shield/integrity/test'));
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
