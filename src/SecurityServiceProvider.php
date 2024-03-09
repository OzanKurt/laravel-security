<?php

namespace OzanKurt\Security;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use OzanKurt\Security\Commands\SendSecurityReportNotificationCommand;
use OzanKurt\Security\Commands\UnblockIpsCommand;
use OzanKurt\Security\Events\AttackDetectedEvent;
use OzanKurt\Security\Listeners\AttackDetectedListener;
use OzanKurt\Security\Listeners\BlockIpListener;
use OzanKurt\Security\Listeners\FailedLoginListener;
use OzanKurt\Security\Listeners\NotifyUsersListener;
use OzanKurt\Security\Listeners\SuccessfulLoginListener;
use OzanKurt\Security\Notifications\Channels\Discord\DiscordChannel;

class SecurityServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/security.php', 'security');

        $this->app->register(\Jenssegers\Agent\AgentServiceProvider::class);
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(Router $router): void
    {
        $langPath = 'vendor/security';

        $langPath = (function_exists('lang_path'))
            ? lang_path($langPath)
            : resource_path('lang/' . $langPath);

        $this->publishes([
            __DIR__ . '/../config/security.php' => config_path('security.php'),
            __DIR__ . '/../resources/lang' => $langPath,
            __DIR__ . '/../database/migrations/create_ips_table.php' => $this->getMigrationPathFor('ip'),
            __DIR__ . '/../database/migrations/create_logs_table.php' => $this->getMigrationPathFor('log'),
        ], 'security');

        $this->registerMiddleware($router);
        $this->registerListeners();
        $this->registerTranslations($langPath);
        $this->registerCommands();
        $this->registerViews();
    }

    protected function registerMiddleware(Router $router): void
    {
        $router->middlewareGroup('firewall.all', config('security.all_middleware'));

        $middlewares = [
            'firewall.agent' => \OzanKurt\Security\Middleware\Agent::class,
            'firewall.bot' => \OzanKurt\Security\Middleware\Bot::class,
            'firewall.ip' => \OzanKurt\Security\Middleware\Ip::class,
            'firewall.geo' => \OzanKurt\Security\Middleware\Geo::class,
            'firewall.lfi' => \OzanKurt\Security\Middleware\Lfi::class,
            'firewall.php' => \OzanKurt\Security\Middleware\Php::class,
            'firewall.referrer' => \OzanKurt\Security\Middleware\Referrer::class,
            'firewall.rfi' => \OzanKurt\Security\Middleware\Rfi::class,
            'firewall.session' => \OzanKurt\Security\Middleware\Session::class,
            'firewall.sqli' => \OzanKurt\Security\Middleware\Sqli::class,
            'firewall.swear' => \OzanKurt\Security\Middleware\Swear::class,
            'firewall.url' => \OzanKurt\Security\Middleware\Url::class,
            'firewall.whitelist' => \OzanKurt\Security\Middleware\Whitelist::class,
            'firewall.xss' => \OzanKurt\Security\Middleware\Xss::class,
            'firewall.keyword' => \OzanKurt\Security\Middleware\Keyword::class,
        ];

        foreach ($middlewares as $name => $class) {
            $router->aliasMiddleware($name, $class);
        }
    }

    protected function registerListeners(): void
    {
        $this->app['events']->listen(AttackDetectedEvent::class, BlockIpListener::class);
        $this->app['events']->listen(AttackDetectedEvent::class, AttackDetectedListener::class);
        $this->app['events']->listen(LoginAuthenticated::class, SuccessfulLoginListener::class);
        $this->app['events']->listen(LoginFailed::class, FailedLoginListener::class);
    }

    protected function registerTranslations($langPath): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'security');
    }

    protected function registerCommands(): void
    {
        $this->commands(UnblockIpsCommand::class);
        $this->commands(SendSecurityReportNotificationCommand::class);

        if (config('security.cron.enabled')) {
            $this->app->booted(function () {
                app(Schedule::class)->command('security:unblock-ips')->cron(config('security.cron.expression'));
            });
        }
    }

    protected function registerViews(): void
    {
        View::addNamespace('security', __DIR__ . '/../resources/views');
    }

    protected function getMigrationPathFor(string $modelKey): string
    {
        $prefix = date('Y_m_d') . '_000000';
        $tableName = $this->getNameTable($modelKey);

        return database_path("migrations/{$prefix}_create_{$tableName}_table.php");
    }

    protected function getNameTable(string $modelKey): string
    {
        $tablePrefix = config('security.database.table_prefix', 'security_');
        $tableName = config("security.database.{$modelKey}.table", $modelKey);

        return $tablePrefix . $tableName;
    }

    protected function registerDiscordChannel(): void
    {
        Notification::resolved(function (ChannelManager $service) {
            $service->extend(DiscordChannel::class, function ($app) {
                return new DiscordChannel();
            });
        });
    }
}
