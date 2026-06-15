<?php

namespace OzanKurt\Shield\Tests\Feature\Integrity;

use Illuminate\Support\Facades\Gate;
use OzanKurt\Shield\Models\IntegrityRun;
use OzanKurt\Shield\Tests\TestCase;

class IntegrityDashboardTest extends TestCase
{
    private string $src;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', 'base64:Lf+1x2r3feOZ2hfF6Ksn6JSwbR4yGJ3vYri6EGr/EuA=');
        $app['config']->set('shield.dashboard.enabled', true);
        $app['config']->set('shield.dashboard.middleware', []); // drop auth for the test
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewShieldDashboard', fn () => true);

        $this->src = sys_get_temp_dir() . '/shield_dash_src_' . uniqid();
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

    public function test_integrity_index_renders(): void
    {
        $this->get(route('shield.integrity.index'))
            ->assertOk()
            ->assertSee('File integrity');
    }

    public function test_runs_datatable_returns_json(): void
    {
        $this->get(route('shield.integrity.runs', ['mode' => 'dataTable']))
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_changes_datatable_returns_json(): void
    {
        $this->get(route('shield.integrity.changes', ['mode' => 'dataTable']))
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_scan_action_runs_and_persists_a_run(): void
    {
        $this->post(route('shield.integrity.scan'), ['disk' => 'test'])
            ->assertOk()
            ->assertJsonStructure(['actions']);

        $this->assertTrue(IntegrityRun::where('disk', 'test')->exists());
    }

    public function test_bless_without_a_prior_run_returns_an_error_toastr(): void
    {
        $response = $this->post(route('shield.integrity.bless'), ['disk' => 'test'])->assertOk();

        $actions = $response->json('actions');
        $this->assertSame('error', $actions[0]['data']['type']);
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
