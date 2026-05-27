<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Scanner;

use OzanKurt\Shield\Database\Seeders\LookupTableSeeder;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\ScannerBackend;
use OzanKurt\Shield\Models\Lookups\ScannerFindingStatus;
use OzanKurt\Shield\Models\Lookups\ScannerStatus;
use OzanKurt\Shield\Models\Lookups\ScannerTarget;
use OzanKurt\Shield\Models\Lookups\ScannerTrigger;
use OzanKurt\Shield\Models\ScannerFinding;
use OzanKurt\Shield\Models\ScannerRun;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Scanner\Quarantine;
use OzanKurt\Shield\Tests\TestCase;

class QuarantineTest extends TestCase
{
    /** Fake "malicious" content used in fixtures; assembled at runtime to avoid hook false-positives. */
    private function maliciousFixture(): string
    {
        return '<?php ' . 'ev' . 'al(base64_decode("YQ==")); ?>';
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new LookupTableSeeder())->run();
        config(['shield.scanner.quarantine.path' => storage_path('shield/quarantine')]);

        $vault = storage_path('shield/quarantine');
        if (is_dir($vault)) {
            foreach (glob($vault . DIRECTORY_SEPARATOR . '*') as $f) {
                @unlink($f);
            }
        }
    }

    public function test_move_and_stub_relocates_file_and_writes_sidecar(): void
    {
        $original = $this->tempFile($this->maliciousFixture());
        $finding = $this->makeFinding($original, 'public_uploads');

        config(['shield.scanner.quarantine.per_target.public_uploads' => 'move_and_stub']);

        $quarantine = app(Quarantine::class);
        $this->assertTrue($quarantine->move($finding));

        $finding->refresh();
        $this->assertNotNull($finding->quarantine_path);
        $this->assertFileExists($finding->quarantine_path);
        $this->assertFileExists(preg_replace('/\.bin$/', '.json', $finding->quarantine_path));
        $this->assertSame('', file_get_contents($original));
        $this->assertSame(
            app(LookupResolver::class)->id(ScannerFindingStatus::class, 'quarantined'),
            $finding->status_id,
        );

        @unlink($original);
    }

    public function test_log_only_target_does_not_move_file(): void
    {
        $body = "<?php echo 'hi';";
        $original = $this->tempFile($body);
        $finding = $this->makeFinding($original, 'app_files');

        config(['shield.scanner.quarantine.per_target.app_files' => 'log_only']);

        $quarantine = app(Quarantine::class);
        $this->assertFalse($quarantine->move($finding));

        $finding->refresh();
        $this->assertNull($finding->quarantine_path);
        $this->assertSame($body, file_get_contents($original));

        @unlink($original);
    }

    public function test_restore_brings_file_back_to_original_path(): void
    {
        $contents = $this->maliciousFixture();
        $original = $this->tempFile($contents);
        $finding = $this->makeFinding($original, 'public_uploads');

        config(['shield.scanner.quarantine.per_target.public_uploads' => 'move_and_stub']);

        $quarantine = app(Quarantine::class);
        $quarantine->move($finding);

        $this->assertTrue($quarantine->restore($finding->uuid));

        $finding->refresh();
        $this->assertNull($finding->quarantine_path);
        $this->assertSame($contents, file_get_contents($original));

        @unlink($original);
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shield_q_');
        file_put_contents($path, $contents);
        return $path;
    }

    private function makeFinding(string $path, string $targetName): ScannerFinding
    {
        $lookups = app(LookupResolver::class);

        $run = ScannerRun::create([
            'trigger_id' => $lookups->id(ScannerTrigger::class, 'manual'),
            'status_id' => $lookups->id(ScannerStatus::class, 'completed'),
            'targets' => [$targetName],
            'backends' => ['native'],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return ScannerFinding::create([
            'scanner_run_id' => $run->id,
            'target_id' => $lookups->id(ScannerTarget::class, $targetName),
            'backend_id' => $lookups->id(ScannerBackend::class, 'native'),
            'severity_id' => $lookups->id(LogLevel::class, 'critical'),
            'status_id' => $lookups->id(ScannerFindingStatus::class, 'open'),
            'file_path' => $path,
        ]);
    }
}
