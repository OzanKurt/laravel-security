<?php

namespace OzanKurt\Security\Tests;

use Illuminate\Contracts\Config\Repository;
use OzanKurt\Security\SecurityServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends OrchestraTestCase
{
    // use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('vendor:publish', ['--tag' => 'security']);

        $this->afterApplicationCreated(function () {
            $this->artisan('migrate:refresh', ['--database' => 'testbench']);
        });

        $this->beforeApplicationDestroyed(function () {
            $this->artisan('migrate:rollback', ['--database' => 'testbench']);
        });
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        // Setup default database to use sqlite :memory:
        tap($app['config'], function (Repository $config) {
            $config->set('database.default', 'testbench');
            $config->set('database.connections.testbench', [
                'driver'   => 'sqlite',
                'database' => 'database.sqlite',
                'prefix'   => '',
            ]);

            // Setup security config
            $config->set(['security' => require __DIR__ . '/../config/security.php']);

            $config->set(['security.database.connection' => 'testbench']);

            $config->set(['security.notifications.mail.enabled' => false]);
            $config->set(['security.middleware.ip.methods' => ['all']]);
            $config->set(['security.middleware.lfi.methods' => ['all']]);
            $config->set(['security.middleware.rfi.methods' => ['all']]);
            $config->set(['security.middleware.sqli.methods' => ['all']]);
            $config->set(['security.middleware.xss.methods' => ['all']]);

            $config->set(['security.notifications.discord.enabled' => true]);
            $config->set(['security.notifications.discord.to' => 'https://discord.com/api/webhooks/1213258698470727770/z48rQz0svhO4WvllVWq_6mh8ehnsJEPg2KnE5Mk7V0q2pBrlQ4Kv0ePwyBFz3xOl5GU9']);
            $config->set(['security.notifications.discord.channel' => \NotificationChannels\Discord\DiscordChannel::class]);
        });
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

    public function getNextClosure()
    {
        return function () {
            return 'next';
        };
    }
}
