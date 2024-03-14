<?php

namespace OzanKurt\Security;

use OzanKurt\Security\Http\Controllers\IpsController;
use OzanKurt\Security\Http\Controllers\DashboardController;
use OzanKurt\Security\Http\Controllers\LogsController;
use voku\helper\AntiXSS;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Events\Login as LoginAuthenticated;
use Illuminate\Auth\Events\Failed as LoginFailed;
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

        $this->app->register(\Jenssegers\Agent\AgentServiceProvider::class);

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
            $this->registerRoutes($router);
        }
    }

    protected function registerRoutes(Router $router): void
    {
        $router->group([
            'namespace' => 'OzanKurt\Security\Http\Controllers',
            'prefix' => config('security.dashboard.route_prefix', 'security'),
            'middleware' => config('security.dashboard.route_middleware', []),
        ], function ($router) {
            $name = config('security.dashboard.route_name', 'security.');
            $router->get('', [DashboardController::class, 'index'])->name($name.'dashboard.index');
            $router->get('/logs', [LogsController::class, 'index'])->name($name.'logs.index');
            $router->get('/ips', [IpsController::class, 'index'])->name($name.'ips.index');

            $router->get('/whitelist', [DashboardController::class, 'whitelist'])->name($name.'whitelist');
            $router->post('/whitelist', [DashboardController::class, 'whitelistStore'])->name($name.'whitelist.store');
            $router->delete('/whitelist/{id}', [DashboardController::class, 'whitelistDestroy'])->name($name.'whitelist.destroy');

            $router->get('/blacklist', [DashboardController::class, 'blacklist'])->name($name.'blacklist');
            $router->post('/blacklist', [DashboardController::class, 'blacklistStore'])->name($name.'blacklist.store');
            $router->delete('/blacklist/{id}', [DashboardController::class, 'blacklistDestroy'])->name($name.'blacklist.destroy');
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
        $this->app['events']->listen(LoginAuthenticated::class, SuccessfulLoginListener::class);
        $this->app['events']->listen(LoginFailed::class, FailedLoginListener::class);
    }

    protected function registerTranslations(): void
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
