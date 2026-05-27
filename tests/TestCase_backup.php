<?php

namespace OzanKurt\Shield\Tests;

use Illuminate\Contracts\Config\Repository;
use OzanKurt\Shield\ShieldServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends OrchestraTestCase
{
    // use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('vendor:publish', ['--tag' => 'shield']);

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

            // Setup shield config
            $config->set(['shield' => require __DIR__ . '/../config/shield.php']);

            $config->set(['shield.database.connection' => 'testbench']);

            $config->set(['shield.notifications.mail.enabled' => false]);
            $config->set(['shield.middleware.ip.methods' => ['all']]);
            $config->set(['shield.middleware.lfi.methods' => ['all']]);
            $config->set(['shield.middleware.rfi.methods' => ['all']]);
            $config->set(['shield.middleware.sqli.methods' => ['all']]);
            $config->set(['shield.middleware.xss.methods' => ['all']]);

            $config->set(['shield.notifications.discord.enabled' => true]);
            $config->set(['shield.notifications.discord.to' => 'https://discord.com/api/webhooks/1213258698470727770/z48rQz0svhO4WvllVWq_6mh8ehnsJEPg2KnE5Mk7V0q2pBrlQ4Kv0ePwyBFz3xOl5GU9']);
            $config->set(['shield.notifications.discord.channel' => \NotificationChannels\Discord\DiscordChannel::class]);
        });
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

    public function getNextClosure()
    {
        return function () {
            return 'next';
        };
    }
}
