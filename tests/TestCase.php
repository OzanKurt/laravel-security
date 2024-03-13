<?php

namespace OzanKurt\Security\Tests;

use OzanKurt\Security\SecurityServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConfig();

        $this->setUpDatabase();

        $this->artisan('vendor:publish', ['--tag' => 'security']);
        // $this->artisan('migrate:refresh', ['--database' => 'testbench']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            SecurityServiceProvider::class,
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

        config(['security' => include __DIR__.'/../config/security.php']);
        config(['security.database.connection' => 'testbench']);

        config(['security.notifications.mail.enabled' => false]);
        config(['security.middleware.ip.methods' => ['all']]);
        config(['security.middleware.lfi.methods' => ['all']]);
        config(['security.middleware.rfi.methods' => ['all']]);
        config(['security.middleware.sqli.methods' => ['all']]);
        config(['security.middleware.xss.methods' => ['all']]);
    }

    public function getNextClosure()
    {
        return function () {
            return 'next';
        };
    }
}
