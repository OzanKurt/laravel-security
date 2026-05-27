<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Scanner;

use OzanKurt\Shield\Services\Scanner\Backends\ComposerAuditBackend;
use OzanKurt\Shield\Tests\TestCase;

class ComposerAuditBackendTest extends TestCase
{
    public function test_name_returns_composer_audit(): void
    {
        $backend = new ComposerAuditBackend();
        $this->assertSame('composer_audit', $backend->name());
    }

    public function test_is_available_returns_true(): void
    {
        $backend = new ComposerAuditBackend();
        $this->assertTrue($backend->isAvailable());
    }

    public function test_is_per_file_returns_false(): void
    {
        $backend = new ComposerAuditBackend();
        $this->assertFalse($backend->isPerFile());
    }

    public function test_scan_file_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        (new ComposerAuditBackend())->scanFile('/some/file.php');
    }

    public function test_backend_implements_interface(): void
    {
        $backend = new ComposerAuditBackend();
        $this->assertInstanceOf(
            \OzanKurt\Shield\Services\Scanner\ScannerBackendInterface::class,
            $backend
        );
    }
}
