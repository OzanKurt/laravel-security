<?php

namespace OzanKurt\Shield\Tests\Feature;

use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Acl\AclEvaluator;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class BypassMechanismTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(AclEvaluator::class)->clearCache();

        $this->app['router']->get('/_test/bypass-target', fn() => 'ok')
            ->middleware(['firewall.bypass', 'firewall.acl']);
    }

    public function test_header_key_bypasses_block(): void
    {
        $this->seedAclBlock('1.1.1.1');

        config(['shield.bypass.ips' => []]);
        putenv('LS_BYPASS_KEY=test-key-12345');

        $this->call('GET', '/_test/bypass-target', server: [
            'REMOTE_ADDR' => '1.1.1.1',
            'HTTP_X_SECURITY_BYPASS' => 'test-key-12345',
        ])->assertOk();

        // Clean up env
        putenv('LS_BYPASS_KEY=');
    }

    public function test_config_ip_bypasses_block(): void
    {
        $this->seedAclBlock('2.2.2.2');

        config(['shield.bypass.ips' => ['2.2.2.2']]);
        putenv('LS_BYPASS_KEY=');

        $this->call('GET', '/_test/bypass-target', server: ['REMOTE_ADDR' => '2.2.2.2'])
            ->assertOk();
    }

    public function test_no_bypass_still_blocks(): void
    {
        $this->seedAclBlock('3.3.3.3');

        config(['shield.bypass.ips' => []]);
        putenv('LS_BYPASS_KEY=');

        $this->call('GET', '/_test/bypass-target', server: ['REMOTE_ADDR' => '3.3.3.3'])
            ->assertStatus(403);
    }

    public function test_wrong_header_key_does_not_bypass(): void
    {
        $this->seedAclBlock('4.4.4.4');

        config(['shield.bypass.ips' => []]);
        putenv('LS_BYPASS_KEY=correct-key');

        $this->call('GET', '/_test/bypass-target', server: [
            'REMOTE_ADDR' => '4.4.4.4',
            'HTTP_X_SECURITY_BYPASS' => 'wrong-key',
        ])->assertStatus(403);

        putenv('LS_BYPASS_KEY=');
    }

    public function test_artisan_bypass_add_inserts_acl_allow(): void
    {
        $this->artisan('shield:bypass-add', ['ip' => '10.0.0.1'])
            ->assertSuccessful();

        $this->assertDatabaseHas('ls_acl', [
            'value' => '10.0.0.1',
            'source' => 'bypass',
        ]);
    }

    public function test_artisan_bypass_add_rejects_invalid_ip(): void
    {
        $this->artisan('shield:bypass-add', ['ip' => 'not-an-ip'])
            ->assertFailed();

        $this->assertDatabaseMissing('ls_acl', ['value' => 'not-an-ip']);
    }

    public function test_artisan_bypass_remove_deletes_entry(): void
    {
        $this->artisan('shield:bypass-add', ['ip' => '10.0.0.2'])->assertSuccessful();
        $this->assertDatabaseHas('ls_acl', ['value' => '10.0.0.2', 'source' => 'bypass', 'deleted_at' => null]);

        $this->artisan('shield:bypass-remove', ['ip' => '10.0.0.2'])->assertSuccessful();

        // Acl uses SoftDeletes, the row is retained but deleted_at is set
        $this->assertDatabaseMissing('ls_acl', ['value' => '10.0.0.2', 'source' => 'bypass', 'deleted_at' => null]);
    }

    public function test_artisan_bypass_list_shows_entries(): void
    {
        $this->artisan('shield:bypass-add', ['ip' => '10.0.0.3'])->assertSuccessful();

        $this->artisan('shield:bypass-list')
            ->assertSuccessful()
            ->expectsOutputToContain('10.0.0.3');
    }

    private function seedAclBlock(string $ip): void
    {
        $lookups = app(LookupResolver::class);
        Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => $ip,
            'source' => 'manual',
        ]);
        app(AclEvaluator::class)->clearCache();
    }
}
