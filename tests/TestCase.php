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
        $create_logs_table = include __DIR__.'/../database/migrations/create_logs_table.php';
        $create_logs_table->up();
        $create_ips_table = include __DIR__.'/../database/migrations/create_ips_table.php';
        $create_ips_table->up();
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
