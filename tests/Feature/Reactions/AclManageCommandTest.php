<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Tests\TestCase;

class AclManageCommandTest extends TestCase
{
    public function testNonInteractiveBanCreatesManualAclEntry()
    {
        $this->artisan('shield:acl --ban=203.0.113.80 --hours=24')->assertExitCode(0);

        $this->assertTrue(
            Acl::query()->where('value', '203.0.113.80')->where('source', 'manual')->exists()
        );
    }

    public function testNonInteractiveUnbanSoftDeletesEntry()
    {
        $this->artisan('shield:acl --ban=203.0.113.81 --hours=1')->assertExitCode(0);
        $this->artisan('shield:acl --unban=203.0.113.81')->assertExitCode(0);

        $this->assertFalse(
            Acl::query()->where('value', '203.0.113.81')->exists() // soft-deleted → excluded
        );
    }

    public function testListRunsWithoutError()
    {
        $this->artisan('shield:acl --list')->assertExitCode(0);
    }

    public function testBareCommandWithoutInteractionFails()
    {
        $this->artisan('shield:acl --no-interaction')->assertExitCode(1);
    }
}
