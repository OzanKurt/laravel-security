<?php

namespace OzanKurt\Shield\Tests;

use OzanKurt\Shield\ShieldServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConfig();

        $this->setUpDatabase();

        $this->artisan('vendor:publish', ['--tag' => 'shield']);
        // $this->artisan('migrate:refresh', ['--database' => 'testbench']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            ShieldServiceProvider::class,
        ];
    }

    protected function setUpDatabase()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        (new \OzanKurt\Shield\Database\Seeders\LookupTableSeeder())->run();
        (new \OzanKurt\Shield\Database\Seeders\BuiltinWafRuleSeeder())->run();
    }

    protected function setUpConfig()
    {
        config(['database.default' => 'testbench']);

        config([
            'database.connections.testbench' => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        ]);

        config(['shield' => include __DIR__.'/../config/shield.php']);
        config(['shield.database.connection' => 'testbench']);

        // Clear the default whitelist so test IP (127.0.0.1) is not whitelisted,
        // allowing the testShouldBlock tests to exercise blocking logic.
        config(['shield.whitelist' => []]);

        // Return a response object instead of calling abort() so that tests can
        // inspect the status code via ->getStatusCode() on the returned response.
        config(['shield.responses.block.abort' => false]);

        config(['shield.notifications.mail.enabled' => false]);
        config(['shield.middleware.ip.methods' => ['all']]);
        config(['shield.middleware.lfi.methods' => ['all']]);
        config(['shield.middleware.rfi.methods' => ['all']]);
        config(['shield.middleware.sqli.methods' => ['all']]);
        config(['shield.middleware.xss.methods' => ['all']]);
    }

    public function getNextClosure()
    {
        return function () {
            return 'next';
        };
    }
}
