<?php

namespace OzanKurt\Shield\Tests\Feature;

use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Models\WafRule;
use OzanKurt\Shield\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_install_seeds_lookups_and_builtin_rules(): void
    {
        $this->artisan('shield:install', ['--no-ip-prompt' => true])
            ->assertSuccessful();

        $this->assertGreaterThan(0, AclKind::count());
        $this->assertGreaterThan(40, WafRule::query()->where('source', 'builtin')->count());
    }

    public function test_install_is_idempotent(): void
    {
        $this->artisan('shield:install', ['--no-ip-prompt' => true]);
        $countAfter1 = WafRule::count();

        $this->artisan('shield:install', ['--no-ip-prompt' => true]);
        $countAfter2 = WafRule::count();

        $this->assertSame($countAfter1, $countAfter2);
    }
}
