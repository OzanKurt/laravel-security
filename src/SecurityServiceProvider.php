<?php

namespace OzanKurt\Security;

use Illuminate\Contracts\Foundation\Application;
use OzanKurt\Security\Http\Controllers\AuthLogsController;
use OzanKurt\Security\Http\Controllers\IpsController;
use OzanKurt\Security\Http\Controllers\DashboardController;
use OzanKurt\Security\Http\Controllers\LogsController;
use voku\helper\AntiXSS;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Access\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Events\Login as AuthLoginEvent;
use Illuminate\Auth\Events\Failed as AuthFailedEvent;
use OzanKurt\Security\Commands\SendSecurityReportNotificationCommand;
use OzanKurt\Security\Commands\UnblockIpsCommand;
use OzanKurt\Security\Events\AttackDetectedEvent;
use OzanKurt\Security\Listeners\AttackDetectedListener;
use OzanKurt\Security\Listeners\BlockIpListener;
use OzanKurt\Security\Listeners\FailedLoginListener;
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

        $this->app->register(\OzanKurt\Agent\AgentServiceProvider::class);

        $this->app->singleton(Security::class, function () {
            $antiXss = new AntiXSS();

            return new Security($antiXss);
        });

        $this->app->alias(Security::class, 'security');
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(Router $router): void
    {
        $this->publishAssets();

        $this->registerMiddleware($router);
        $this->registerListeners();
        $this->registerTranslations();
        $this->registerCommands();
        $this->registerViews();

        if (config('security.dashboard.enabled')) {
            $this->callAfterResolving(\Illuminate\Contracts\Auth\Access\Gate::class, function (Gate $gate, Application $app) {
                $gate->define('viewSecurityDashboard', fn ($user = null) => false);
            });

            $this->callAfterResolving('router', function (Router $router, Application $app) {
                $this->registerRoutes($router);
            });
        }
    }

    protected function registerRoutes(Router $router): void
    {
        $middleware = config('security.dashboard.middleware', []);

        $name = config('security.dashboard.route_name', 'security.');
        $router->group([
            'namespace' => 'OzanKurt\Security\Http\Controllers',
            'prefix' => config('security.dashboard.route_prefix', 'security'),
            'middleware' => [
                'web',
                ...$middleware,
            ],
            'as' => $name,
        ], function ($router) {
            $router->get('', [DashboardController::class, 'index'])->name('dashboard.index');

            $router->get('ips', [IpsController::class, 'index'])->name('ips.index');
            $router->post('ips/{ip:id}/action', [IpsController::class, 'postAction'])->name('ips.action');

            $router->get('logs', [LogsController::class, 'index'])->name('logs.index');
            $router->post('logs/{log:id}/action', [LogsController::class, 'postAction'])->name('logs.action');

            $router->get('auth-logs', [AuthLogsController::class, 'index'])->name('auth-logs.index');
            $router->post('auth-logs/{authLog:id}/action', [AuthLogsController::class, 'postAction'])->name('auth-logs.action');
        });
    }

    protected function registerMiddleware(Router $router): void
    {
        $router->middlewareGroup('firewall.all', config('security.all_middleware'));

        $middlewares = [
            'firewall.agent' => \OzanKurt\Security\Firewall\Middleware\Agent::class,
            'firewall.bot' => \OzanKurt\Security\Firewall\Middleware\Bot::class,
            'firewall.ip' => \OzanKurt\Security\Firewall\Middleware\Ip::class,
            'firewall.geo' => \OzanKurt\Security\Firewall\Middleware\Geo::class,
            'firewall.lfi' => \OzanKurt\Security\Firewall\Middleware\Lfi::class,
            'firewall.php' => \OzanKurt\Security\Firewall\Middleware\Php::class,
            'firewall.referrer' => \OzanKurt\Security\Firewall\Middleware\Referrer::class,
            'firewall.rfi' => \OzanKurt\Security\Firewall\Middleware\Rfi::class,
            'firewall.session' => \OzanKurt\Security\Firewall\Middleware\Session::class,
            'firewall.sqli' => \OzanKurt\Security\Firewall\Middleware\Sqli::class,
            'firewall.swear' => \OzanKurt\Security\Firewall\Middleware\Swear::class,
            'firewall.url' => \OzanKurt\Security\Firewall\Middleware\Url::class,
            'firewall.whitelist' => \OzanKurt\Security\Firewall\Middleware\Whitelist::class,
            'firewall.xss' => \OzanKurt\Security\Firewall\Middleware\Xss::class,
            'firewall.keyword' => \OzanKurt\Security\Firewall\Middleware\Keyword::class,
        ];

        foreach ($middlewares as $name => $class) {
            $router->aliasMiddleware($name, $class);
        }
    }

    protected function registerListeners(): void
    {
        $this->app['events']->listen(AttackDetectedEvent::class, BlockIpListener::class);
        $this->app['events']->listen(AttackDetectedEvent::class, AttackDetectedListener::class);
        $this->app['events']->listen(AuthLoginEvent::class, SuccessfulLoginListener::class);
        $this->app['events']->listen(AuthFailedEvent::class, FailedLoginListener::class);
    }

    protected function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'security');
    }

    protected function registerCommands(): void
    {
        $this->commands(UnblockIpsCommand::class);
        $this->commands(SendSecurityReportNotificationCommand::class);

        $this->app->booted(function () {
            if (config('security.crons.unblock_ips.enabled')) {
                app(Schedule::class)
                    ->command('security:unblock-ips')
                    ->cron(config('security.crons.unblock_ips.cron_expression'));
            }

            if (config('security.notifications.security_report.enabled')) {
                app(Schedule::class)
                    ->command('security:send-security-report-notification')
                    ->cron(config('security.crons.security_report.cron_expression'));
            }
        });
    }

    protected function registerViews(): void
    {
        View::addNamespace('security', __DIR__ . '/../resources/views');
    }

    protected function getMigrationPathFor(string $modelKey): string
    {
        $prefix = '2024_01_01_000000';
        $tableName = $this->getNameTable($modelKey);

        return database_path("migrations/{$prefix}_create_{$tableName}_table.php");
    }

    protected function getNameTable(string $modelKey): string
    {
        $tablePrefix = config('security.database.table_prefix', 'security_');
        $tableName = config("security.database.{$modelKey}.table", $modelKey);

        return $tablePrefix . $tableName;
    }

    public function publishAssets(): void
    {
        // config
        $this->publishes([
            __DIR__ . '/../config/security.php' => config_path('security.php'),
        ], 'security-config');

        // lang
        $langPath = 'vendor/security';
        $langPath = (function_exists('lang_path'))
            ? lang_path($langPath)
            : resource_path('lang/' . $langPath);

        $this->publishes([
            __DIR__ . '/../resources/lang' => $langPath,
        ], 'security-lang');

        // migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/create_auth_logs_table.php' => $this->getMigrationPathFor('auth_log'),
            __DIR__ . '/../database/migrations/create_ips_table.php' => $this->getMigrationPathFor('ip'),
            __DIR__ . '/../database/migrations/create_logs_table.php' => $this->getMigrationPathFor('log'),
        ], 'security-migrations');

        // public
        $this->publishes([
            __DIR__ . '/../public' => public_path('vendor/security'),
        ], 'security-assets');
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
