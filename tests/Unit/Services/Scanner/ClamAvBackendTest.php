<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Scanner;

use OzanKurt\Shield\Services\Scanner\Backends\ClamAvBackend;
use OzanKurt\Shield\Tests\TestCase;

class ClamAvBackendTest extends TestCase
{
    public function test_name_returns_clamav(): void
    {
        $backend = new ClamAvBackend();
        $this->assertSame('clamav', $backend->name());
    }

    public function test_is_per_file_returns_true(): void
    {
        $backend = new ClamAvBackend();
        $this->assertTrue($backend->isPerFile());
    }

    public function test_is_available_returns_false_when_disabled_in_config(): void
    {
        config(['shield.scanner.clamav.enabled' => false]);
        $backend = new ClamAvBackend();
        $this->assertFalse($backend->isAvailable());
    }

    public function test_is_available_returns_false_when_quahog_not_installed(): void
    {
        config(['shield.scanner.clamav.enabled' => true]);
        // Quahog is not installed in test environment
        $backend = new ClamAvBackend();
        $this->assertFalse($backend->isAvailable());
    }

    public function test_scan_run_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        (new ClamAvBackend())->scanRun();
    }

    public function test_scan_file_returns_empty_for_nonexistent_file(): void
    {
        $backend = new ClamAvBackend();
        $result = $backend->scanFile('/nonexistent/file.php');
        $this->assertSame([], $result);
    }
}
