<?php

namespace OzanKurt\Shield;

use Illuminate\Contracts\Foundation\Application;
use OzanKurt\Shield\Http\Controllers\AclController;
use OzanKurt\Shield\Http\Controllers\AuditLogController;
use OzanKurt\Shield\Http\Controllers\AuthLogsController;
use OzanKurt\Shield\Http\Controllers\CacheController;
use OzanKurt\Shield\Http\Controllers\DashboardController;
use OzanKurt\Shield\Http\Controllers\LogsController;
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
use OzanKurt\Shield\Commands\SendSecurityReportNotificationCommand;
use OzanKurt\Shield\Commands\UnblockIpsCommand;
use OzanKurt\Shield\Events\AttackDetectedEvent;
use OzanKurt\Shield\Listeners\AttackDetectedListener;
use OzanKurt\Shield\Listeners\BlockIpListener;
use OzanKurt\Shield\Listeners\FailedLoginListener;
use OzanKurt\Shield\Listeners\SuccessfulLoginListener;
use OzanKurt\Shield\Notifications\Channels\Discord\DiscordChannel;

class ShieldServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/shield.php', 'shield');

        $this->app->register(\OzanKurt\Agent\AgentServiceProvider::class);

        $this->app->singleton(Shield::class, function () {
            $antiXss = new AntiXSS();

            return new Shield($antiXss);
        });

        $this->app->alias(Shield::class, 'shield');

        $this->app->singleton(\OzanKurt\Shield\Support\CorrelationId::class);

        $this->app->singleton(\OzanKurt\Shield\Services\Lookups\LookupResolver::class);

        $this->app->singleton(\OzanKurt\Shield\Services\Acl\AclEvaluator::class);

        $this->app->singleton(\OzanKurt\Shield\Services\Waf\WafRuleResolver::class);

        $this->app->singleton(\OzanKurt\Shield\Services\Audit\HmacChain::class, function ($app) {
            return new \OzanKurt\Shield\Services\Audit\HmacChain(
                env('LS_AUDIT_HMAC_SECRET', 'dev-secret-please-set-LS_AUDIT_HMAC_SECRET'),
            );
        });
        $this->app->singleton(\OzanKurt\Shield\Services\Audit\AuditLogger::class);

        $this->app->singleton(\OzanKurt\Shield\Services\Audit\FileDriftDetector::class);

        $this->app->singleton(\OzanKurt\Shield\Services\Scanner\Scanner::class, function ($app) {
            return new \OzanKurt\Shield\Services\Scanner\Scanner(
                [
                    new \OzanKurt\Shield\Services\Scanner\Backends\NativeBackend(),
                ],
                $app->make(\OzanKurt\Shield\Services\Lookups\LookupResolver::class),
                $app->make(\OzanKurt\Shield\Support\CorrelationId::class),
            );
        });
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

        if (config('shield.dashboard.enabled')) {
            $this->callAfterResolving(\Illuminate\Contracts\Auth\Access\Gate::class, function (Gate $gate, Application $app) {
                $gate->define('viewShieldDashboard', fn ($user = null) => false);
            });

            $this->callAfterResolving('router', function (Router $router, Application $app) {
                $this->registerRoutes($router);
            });
        }
    }

    protected function registerRoutes(Router $router): void
    {
        $middleware = config('shield.dashboard.middleware', []);

        $name = config('shield.dashboard.route_name', 'shield.');
        $router->group([
            'namespace' => 'OzanKurt\Shield\Http\Controllers',
            'prefix' => config('shield.dashboard.route_prefix', 'shield'),
            'middleware' => [
                'web',
                ...$middleware,
            ],
            'as' => $name,
        ], function ($router) {
            $router->get('', [DashboardController::class, 'index'])->name('dashboard.index');

            // ACL (replaces old ips routes)
            $router->get('acl', [AclController::class, 'index'])->name('acl.index');
            $router->post('acl/{acl:id}/action', [AclController::class, 'postAction'])->name('acl.action');
            // Legacy ips aliases for backwards compatibility
            $router->get('ips', [AclController::class, 'index'])->name('ips.index');
            $router->post('ips/{acl:id}/action', [AclController::class, 'postAction'])->name('ips.action');

            $router->get('logs', [LogsController::class, 'index'])->name('logs.index');

            $router->get('auth-logs', [AuthLogsController::class, 'index'])->name('auth-logs.index');

            $router->get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');

            $router->get('cache', [CacheController::class, 'index'])->name('cache.index');
            $router->post('cache/clear', [CacheController::class, 'clear'])->name('cache.clear');
        });
    }

    protected function registerMiddleware(Router $router): void
    {
        $router->aliasMiddleware('firewall.correlation', \OzanKurt\Shield\Http\Middleware\AttachCorrelationId::class);
        $router->aliasMiddleware('firewall.bypass', \OzanKurt\Shield\Firewall\Middleware\Bypass::class);
        $router->aliasMiddleware('firewall.acl', \OzanKurt\Shield\Firewall\Middleware\Acl::class);

        // firewall.all group: correlation → bypass → acl → additional configured middlewares
        // bypass must come BEFORE acl so the acl short-circuit can fire on bypassed requests
        $router->middlewareGroup('firewall.all', array_merge(
            ['firewall.correlation', 'firewall.bypass', 'firewall.acl'],
            config('shield.all_middleware', [])
        ));

        $middlewares = [
            'firewall.agent' => \OzanKurt\Shield\Firewall\Middleware\Agent::class,
            'firewall.bot' => \OzanKurt\Shield\Firewall\Middleware\Bot::class,
            'firewall.ip' => \OzanKurt\Shield\Firewall\Middleware\Ip::class,
            'firewall.geo' => \OzanKurt\Shield\Firewall\Middleware\Geo::class,
            'firewall.lfi' => \OzanKurt\Shield\Firewall\Middleware\Lfi::class,
            'firewall.php' => \OzanKurt\Shield\Firewall\Middleware\Php::class,
            'firewall.referrer' => \OzanKurt\Shield\Firewall\Middleware\Referrer::class,
            'firewall.rfi' => \OzanKurt\Shield\Firewall\Middleware\Rfi::class,
            'firewall.session' => \OzanKurt\Shield\Firewall\Middleware\Session::class,
            'firewall.sqli' => \OzanKurt\Shield\Firewall\Middleware\Sqli::class,
            'firewall.swear' => \OzanKurt\Shield\Firewall\Middleware\Swear::class,
            'firewall.url' => \OzanKurt\Shield\Firewall\Middleware\Url::class,
            'firewall.whitelist' => \OzanKurt\Shield\Firewall\Middleware\Whitelist::class,
            'firewall.xss' => \OzanKurt\Shield\Firewall\Middleware\Xss::class,
            'firewall.keyword' => \OzanKurt\Shield\Firewall\Middleware\Keyword::class,
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

        \OzanKurt\Shield\Models\Acl::observe(\OzanKurt\Shield\Observers\AclObserver::class);
        \OzanKurt\Shield\Models\WafRule::observe(\OzanKurt\Shield\Observers\WafRuleObserver::class);
    }

    protected function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'shield');
    }

    protected function registerCommands(): void
    {
        $this->commands(UnblockIpsCommand::class);
        $this->commands(SendSecurityReportNotificationCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\BypassAddCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\BypassRemoveCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\BypassListCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\InstallCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\AuditDriftCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\SignaturesSyncCommand::class);

        $this->app->booted(function () {
            if (config('shield.crons.unblock_ips.enabled')) {
                app(Schedule::class)
                    ->command('shield:unblock-ips')
                    ->cron(config('shield.crons.unblock_ips.cron_expression'));
            }

            if (config('shield.notifications.security_report.enabled')) {
                app(Schedule::class)
                    ->command('shield:send-security-report-notification')
                    ->cron(config('shield.crons.security_report.cron_expression'));
            }

            if (config('shield.audit.drift.enabled', true)) {
                app(Schedule::class)
                    ->command('shield:audit-drift')
                    ->cron(config('shield.audit.drift.cron', '0 4 * * *'));
            }

            app(Schedule::class)
                ->command('shield:signatures-sync')
                ->cron(config('shield.scanner.signatures.sync_cron', '0 5 * * *'));
        });
    }

    protected function registerViews(): void
    {
        View::addNamespace('shield', __DIR__ . '/../resources/views');
    }

    protected function getMigrationPathFor(string $modelKey): string
    {
        $prefix = '2024_01_01_000000';
        $tableName = $this->getNameTable($modelKey);

        return database_path("migrations/{$prefix}_create_{$tableName}_table.php");
    }

    protected function getNameTable(string $modelKey): string
    {
        $tablePrefix = config('shield.database.table_prefix', 'security_');
        $tableName = config("shield.database.{$modelKey}.table", $modelKey);

        return $tablePrefix . $tableName;
    }

    public function publishAssets(): void
    {
        // config
        $this->publishes([
            __DIR__ . '/../config/shield.php' => config_path('shield.php'),
        ], 'shield-config');

        // lang
        $langPath = 'vendor/shield';
        $langPath = (function_exists('lang_path'))
            ? lang_path($langPath)
            : resource_path('lang/' . $langPath);

        $this->publishes([
            __DIR__ . '/../resources/lang' => $langPath,
        ], 'shield-lang');

        // migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/create_auth_logs_table.php' => $this->getMigrationPathFor('auth_log'),
            __DIR__ . '/../database/migrations/create_ips_table.php' => $this->getMigrationPathFor('ip'),
            __DIR__ . '/../database/migrations/create_logs_table.php' => $this->getMigrationPathFor('log'),
        ], 'shield-migrations');

        // public
        $this->publishes([
            __DIR__ . '/../public' => public_path('vendor/shield'),
        ], 'shield-assets');
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
