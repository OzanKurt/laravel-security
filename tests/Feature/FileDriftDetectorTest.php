<?php

namespace OzanKurt\Shield\Tests\Feature;

use Illuminate\Support\Facades\File;
use OzanKurt\Shield\Models\AuditLog;
use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Services\Audit\FileDriftDetector;
use OzanKurt\Shield\Tests\TestCase;

class FileDriftDetectorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a dedicated temp directory for test files
        $this->tmpDir = sys_get_temp_dir() . '/shield_drift_test_' . uniqid();
        File::makeDirectory($this->tmpDir, 0755, true);

        // Point baseline to within tmpDir so tests are isolated
        config(['shield.audit.drift.enabled' => true]);
        config(['shield.audit.drift.baseline_path' => $this->tmpDir . '/baseline.json']);

        // Monitor only files within the tmpDir
        config(['shield.audit.drift.paths' => [
            $this->tmpDir . '/monitored.txt' => null,
        ]]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        File::deleteDirectory($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // First run establishes baseline, no audit entries
    // -------------------------------------------------------------------------

    public function test_first_run_writes_baseline_and_emits_no_entries(): void
    {
        File::put($this->tmpDir . '/monitored.txt', 'initial content');

        $detector = $this->makeDetector();
        $findings = $detector->detect();

        $this->assertEmpty($findings, 'First run should return no findings');
        $this->assertFileExists($this->tmpDir . '/baseline.json', 'Baseline should be created');
        $this->assertSame(0, AuditLog::count(), 'No audit entries on first run');
    }

    // -------------------------------------------------------------------------
    // Second run with unchanged files: no drift
    // -------------------------------------------------------------------------

    public function test_second_run_with_no_changes_emits_no_entries(): void
    {
        File::put($this->tmpDir . '/monitored.txt', 'stable content');

        $detector = $this->makeDetector();
        $detector->detect(); // establish baseline
        $findings = $detector->detect(); // second pass

        $this->assertEmpty($findings);
        $this->assertSame(0, AuditLog::count());
    }

    // -------------------------------------------------------------------------
    // Changed file triggers audit entry
    // -------------------------------------------------------------------------

    public function test_changed_file_emits_file_drift_audit_entry(): void
    {
        File::put($this->tmpDir . '/monitored.txt', 'original');

        $detector = $this->makeDetector();
        $detector->detect(); // establish baseline

        // Modify the file
        File::put($this->tmpDir . '/monitored.txt', 'modified content');

        $findings = $detector->detect();

        $this->assertCount(1, $findings);
        $this->assertEquals('changed', $findings[0]['status']);

        // An AuditLog entry must have been emitted
        $this->assertGreaterThan(0, AuditLog::count(), 'AuditLog entry should be created for drift');
    }

    // -------------------------------------------------------------------------
    // Kind = file.drift for arbitrary paths
    // -------------------------------------------------------------------------

    public function test_drift_kind_for_arbitrary_path_is_file_drift(): void
    {
        File::put($this->tmpDir . '/monitored.txt', 'v1');
        $detector = $this->makeDetector();
        $detector->detect();

        File::put($this->tmpDir . '/monitored.txt', 'v2');
        $findings = $detector->detect();

        $this->assertEquals('file.drift', $findings[0]['kind']);
    }

    // -------------------------------------------------------------------------
    // Removed file also triggers drift
    // -------------------------------------------------------------------------

    public function test_removed_file_emits_drift_entry(): void
    {
        File::put($this->tmpDir . '/monitored.txt', 'exists');
        $detector = $this->makeDetector();
        $detector->detect();

        File::delete($this->tmpDir . '/monitored.txt');
        $findings = $detector->detect();

        $this->assertCount(1, $findings);
        $this->assertEquals('removed', $findings[0]['status']);
        $this->assertGreaterThan(0, AuditLog::count());
    }

    // -------------------------------------------------------------------------
    // Baseline updates after each run
    // -------------------------------------------------------------------------

    public function test_baseline_updates_so_subsequent_run_has_no_drift(): void
    {
        File::put($this->tmpDir . '/monitored.txt', 'v1');
        $detector = $this->makeDetector();
        $detector->detect(); // baseline = v1

        File::put($this->tmpDir . '/monitored.txt', 'v2');
        $detector->detect(); // finds drift, updates baseline to v2

        $findings = $detector->detect(); // baseline is v2, no drift
        $this->assertEmpty($findings);
    }

    // -------------------------------------------------------------------------
    // Disabled: detect returns empty array
    // -------------------------------------------------------------------------

    public function test_detect_returns_empty_when_disabled(): void
    {
        config(['shield.audit.drift.enabled' => false]);
        File::put($this->tmpDir . '/monitored.txt', 'content');

        $detector = $this->makeDetector();
        $findings = $detector->detect();

        $this->assertEmpty($findings);
        $this->assertFileDoesNotExist($this->tmpDir . '/baseline.json');
    }

    // -------------------------------------------------------------------------
    // Helper: kind for .env path
    // -------------------------------------------------------------------------

    public function test_kind_for_env_path_is_env_changed(): void
    {
        // Use reflection to test the private method directly
        $detector = $this->makeDetector();
        $ref = new \ReflectionMethod($detector, 'kindForPath');
        $ref->setAccessible(true);

        $this->assertEquals('.env.changed', $ref->invoke($detector, '.env'));
        $this->assertEquals('.env.changed', $ref->invoke($detector, '.env.production'));
        $this->assertEquals('composer.changed', $ref->invoke($detector, 'composer.json'));
        $this->assertEquals('composer.changed', $ref->invoke($detector, 'composer.lock'));
        $this->assertEquals('config.drift', $ref->invoke($detector, 'config/app.php'));
        $this->assertEquals('file.drift', $ref->invoke($detector, 'routes/web.php'));
    }

    // -------------------------------------------------------------------------
    // Private factory
    // -------------------------------------------------------------------------

    private function makeDetector(): FileDriftDetector
    {
        return app(FileDriftDetector::class);
    }
}
