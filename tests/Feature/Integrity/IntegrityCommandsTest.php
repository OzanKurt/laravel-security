<?php

namespace OzanKurt\Shield\Tests\Feature\Integrity;

use OzanKurt\Shield\Models\IntegrityChange;
use OzanKurt\Shield\Models\IntegrityRun;
use OzanKurt\Shield\Models\Lookups\IntegrityChangeType;
use OzanKurt\Shield\Models\Lookups\IntegrityComparisonBasis;
use OzanKurt\Shield\Models\Lookups\IntegrityStatus;
use OzanKurt\Shield\Models\Lookups\ScannerTrigger;
use OzanKurt\Shield\Services\Integrity\IntegrityScanner;
use OzanKurt\Shield\Tests\TestCase;

class IntegrityCommandsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();

        $this->src = sys_get_temp_dir() . '/shield_cmd_src_' . uniqid();
        @mkdir($this->src . '/app', 0777, true);
        file_put_contents($this->src . '/app/Service.php', '<?php // v1');

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

        $this->deleteTree(storage_path('shield/integrity/test'));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->src);
        $this->deleteTree(storage_path('shield/integrity/test'));
        parent::tearDown();
    }

    public function test_status_command_succeeds_after_a_scan(): void
    {
        (new IntegrityScanner())->run('test', 'manual');

        $this->artisan('shield:integrity-status', ['--disk' => 'test'])->assertSuccessful();
    }

    public function test_heartbeat_fails_when_there_is_no_recent_run(): void
    {
        $this->artisan('shield:integrity-heartbeat', ['--disk' => 'test'])->assertExitCode(1);
    }

    public function test_heartbeat_succeeds_after_a_scan(): void
    {
        (new IntegrityScanner())->run('test', 'manual');

        $this->artisan('shield:integrity-heartbeat', ['--disk' => 'test'])->assertExitCode(0);
    }

    public function test_prune_deletes_runs_and_changes_past_retention(): void
    {
        $run = IntegrityRun::create([
            'status_id' => IntegrityStatus::where('name', 'completed')->value('id'),
            'trigger_id' => ScannerTrigger::where('name', 'manual')->value('id'),
            'disk' => 'test',
        ]);
        $change = IntegrityChange::create([
            'integrity_run_id' => $run->id,
            'change_type_id' => IntegrityChangeType::where('name', 'new')->value('id'),
            'compared_to_id' => IntegrityComparisonBasis::where('name', 'last_run')->value('id'),
            'path' => 'app/Old.php',
        ]);

        // Backdate beyond retention (runs_days default 90).
        IntegrityRun::where('id', $run->id)->update(['created_at' => now()->subDays(200)]);
        IntegrityChange::where('id', $change->id)->update(['created_at' => now()->subDays(200)]);

        $this->artisan('shield:integrity-prune')->assertSuccessful();

        $this->assertFalse(IntegrityRun::where('id', $run->id)->exists());
        $this->assertFalse(IntegrityChange::where('id', $change->id)->exists());
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
