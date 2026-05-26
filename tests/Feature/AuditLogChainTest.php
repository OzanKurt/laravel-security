<?php

namespace OzanKurt\Shield\Tests\Feature;

use OzanKurt\Shield\Models\AuditLog;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Tests\TestCase;

class AuditLogChainTest extends TestCase
{
    public function test_chain_is_verifiable_when_intact(): void
    {
        $logger = app(AuditLogger::class);
        $logger->log('shield.installed', 'Fresh install');
        $logger->log('acl.added', 'Added entry');
        $logger->log('auth.login', 'User 1 logged in');

        $this->assertEmpty($logger->verify());
    }

    public function test_chain_breaks_when_record_tampered(): void
    {
        $logger = app(AuditLogger::class);
        $logger->log('auth.login', 'A');
        $entry = $logger->log('auth.login', 'B');
        $logger->log('auth.login', 'C');

        AuditLog::where('id', $entry->id)->update(['description' => 'TAMPERED']);

        $issues = $logger->verify();
        $this->assertNotEmpty($issues);
    }
}
