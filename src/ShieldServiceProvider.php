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

        $this->app->singleton(\OzanKurt\Shield\Services\Reactions\CloudflareClient::class);

        $this->app->singleton(\OzanKurt\Shield\Services\Reactions\ReactionManager::class, function ($app) {
            return new \OzanKurt\Shield\Services\Reactions\ReactionManager([
                $app->make(\OzanKurt\Shield\Services\Reactions\CloudflareReaction::class),
                $app->make(\OzanKurt\Shield\Services\Reactions\AbuseIpDbReportReaction::class),
            ]);
        });

        $this->app->singleton(\OzanKurt\Shield\Support\CorrelationId::class);
        $this->app->singleton(\OzanKurt\Shield\Support\CspNonce::class);
        $this->app->singleton(\OzanKurt\Shield\Support\RequestDataRedactor::class);
        $this->app->singleton(\OzanKurt\Shield\Services\Scoring\SuspicionScorer::class);
        $this->app->singleton(\OzanKurt\Shield\Services\Audit\EnvAuditor::class);
        $this->app->singleton(\OzanKurt\Shield\Services\Network\TrustedProxiesService::class);

        $this->app->singleton(\OzanKurt\Shield\Services\ThreatFeed\FeedRunner::class, function ($app) {
            $providers = [];
            foreach ((array) config('shield.threat_feed.providers', []) as $providerClass) {
                if (class_exists($providerClass)) {
                    $providers[] = $app->make($providerClass);
                }
            }
            return new \OzanKurt\Shield\Services\ThreatFeed\FeedRunner(
                $providers,
                $app->make(\OzanKurt\Shield\Services\Audit\AuditLogger::class),
            );
        });

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

        // Auto-load all package-shipped migrations. Consumer apps no longer
        // need to publish migration stubs to run them, they execute from
        // the vendor directory on `php artisan migrate` like Sanctum/Telescope.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->registerMiddleware($router);
        $this->registerListeners();
        $this->registerTranslations();
        $this->registerCommands();
        $this->registerViews();

        $this->registerSpatieMediaLibraryIntegration();
        $this->registerCspNonceBladeDirective();
        $this->registerHoneypotRoutes($router);
        $this->registerPreconfiguredRateLimiters();

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

            $router->get('live-traffic', [\OzanKurt\Shield\Http\Controllers\LiveTrafficController::class, 'index'])->name('live-traffic.index');

            $router->get('cache', [CacheController::class, 'index'])->name('cache.index');
            $router->post('cache/clear', [CacheController::class, 'clear'])->name('cache.clear');

            // WAF rules
            $router->get('rules', [\OzanKurt\Shield\Http\Controllers\WafRulesController::class, 'index'])->name('rules.index');
            $router->post('rules', [\OzanKurt\Shield\Http\Controllers\WafRulesController::class, 'store'])->name('rules.store');
            $router->put('rules/{id}', [\OzanKurt\Shield\Http\Controllers\WafRulesController::class, 'update'])->name('rules.update');
            $router->post('rules/{id}/toggle', [\OzanKurt\Shield\Http\Controllers\WafRulesController::class, 'toggle'])->name('rules.toggle');
            $router->delete('rules/{id}', [\OzanKurt\Shield\Http\Controllers\WafRulesController::class, 'destroy'])->name('rules.destroy');

            // Scanner
            $router->get('scanner', [\OzanKurt\Shield\Http\Controllers\ScannerController::class, 'index'])->name('scanner.index');
            $router->get('scanner/runs', [\OzanKurt\Shield\Http\Controllers\ScannerController::class, 'runs'])->name('scanner.runs');
            $router->get('scanner/findings', [\OzanKurt\Shield\Http\Controllers\ScannerController::class, 'findings'])->name('scanner.findings');
            $router->get('scanner/signatures', [\OzanKurt\Shield\Http\Controllers\ScannerController::class, 'signatures'])->name('scanner.signatures');
            $router->post('scanner/run', [\OzanKurt\Shield\Http\Controllers\ScannerController::class, 'startScan'])->name('scanner.run');

            // File integrity
            $router->get('integrity', [\OzanKurt\Shield\Http\Controllers\IntegrityController::class, 'index'])->name('integrity.index');
            $router->get('integrity/runs', [\OzanKurt\Shield\Http\Controllers\IntegrityController::class, 'runs'])->name('integrity.runs');
            $router->get('integrity/changes', [\OzanKurt\Shield\Http\Controllers\IntegrityController::class, 'changes'])->name('integrity.changes');
            $router->post('integrity/scan', [\OzanKurt\Shield\Http\Controllers\IntegrityController::class, 'scan'])->name('integrity.scan');
            $router->post('integrity/bless', [\OzanKurt\Shield\Http\Controllers\IntegrityController::class, 'bless'])->name('integrity.bless');

            // Threat feed
            $router->get('threat-feed', function () {
                return app(\OzanKurt\Shield\Http\Controllers\ThreatFeedController::class)->index(
                    array_map(fn ($c) => app($c), (array) config('shield.threat_feed.providers', [])),
                    app(\OzanKurt\Shield\Services\Lookups\LookupResolver::class),
                );
            })->name('threat-feed.index');

            // Composer audit
            $router->get('composer-audit', [\OzanKurt\Shield\Http\Controllers\ComposerAuditController::class, 'index'])->name('composer-audit.index');
            $router->post('composer-audit/refresh', [\OzanKurt\Shield\Http\Controllers\ComposerAuditController::class, 'refresh'])->name('composer-audit.refresh');

            // Diagnostics
            $router->get('diagnostics', [\OzanKurt\Shield\Http\Controllers\DiagnosticsController::class, 'index'])->name('diagnostics.index');

            // Premium license
            $router->get('license', [\OzanKurt\Shield\Http\Controllers\LicenseController::class, 'index'])->name('license.index');
            $router->post('license/refresh', [\OzanKurt\Shield\Http\Controllers\LicenseController::class, 'refresh'])->name('license.refresh');
            $router->post('license/clear', [\OzanKurt\Shield\Http\Controllers\LicenseController::class, 'clear'])->name('license.clear');
            $router->post('license/test', [\OzanKurt\Shield\Http\Controllers\LicenseController::class, 'test'])->name('license.test');

            // Webhook deliveries audit
            $router->get('webhook-deliveries', [\OzanKurt\Shield\Http\Controllers\WebhookDeliveriesController::class, 'index'])->name('webhook-deliveries.index');
            $router->post('webhook-deliveries/{id}/retry', [\OzanKurt\Shield\Http\Controllers\WebhookDeliveriesController::class, 'retry'])->name('webhook-deliveries.retry');
        });
    }

    protected function registerMiddleware(Router $router): void
    {
        $router->aliasMiddleware('firewall.correlation', \OzanKurt\Shield\Http\Middleware\AttachCorrelationId::class);
        $router->aliasMiddleware('firewall.bypass', \OzanKurt\Shield\Firewall\Middleware\Bypass::class);
        $router->aliasMiddleware('firewall.acl', \OzanKurt\Shield\Firewall\Middleware\Acl::class);
        $router->aliasMiddleware('firewall.live_traffic', \OzanKurt\Shield\Http\Middleware\LiveTrafficCapture::class);
        $router->aliasMiddleware('firewall.av_uploads', \OzanKurt\Shield\Firewall\Middleware\AvUploads::class);
        $router->aliasMiddleware('firewall.headers', \OzanKurt\Shield\Firewall\Middleware\SecurityHeaders::class);
        $router->aliasMiddleware('firewall.https', \OzanKurt\Shield\Firewall\Middleware\EnforceHttps::class);
        $router->aliasMiddleware('firewall.disabled_routes', \OzanKurt\Shield\Firewall\Middleware\DisabledRoutes::class);

        // firewall.all group: correlation → bypass → acl → live_traffic (terminable) → configured middlewares
        // bypass must come BEFORE acl so the acl short-circuit can fire on bypassed requests
        $router->middlewareGroup('firewall.all', array_merge(
            ['firewall.correlation', 'firewall.bypass', 'firewall.acl', 'firewall.live_traffic'],
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
        $this->app['events']->listen(
            \OzanKurt\Shield\Events\IntegrityScanCompletedEvent::class,
            \OzanKurt\Shield\Listeners\IntegrityScanCompletedListener::class
        );

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
        $this->commands(\OzanKurt\Shield\Console\Commands\WatchCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\ScanCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\ScanStatusCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\ScanCancelCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\IntegrityScanCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\IntegrityBlessCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\IntegrityStatusCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\IntegrityPruneCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\IntegrityHeartbeatCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\QuarantineListCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\QuarantineRestoreCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\ClamavStatusCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\ClamavUpdateCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\ReportSendCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\ReportTestCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\FeedSyncCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\ExportCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\ImportCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\LicenseStatusCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\LicenseCheckCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\LicenseClearCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\HeartbeatCommand::class);
        $this->commands(\OzanKurt\Shield\Console\Commands\CentralTestCommand::class);

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

            if (config('shield.integrity.schedule.enabled', false)) {
                app(Schedule::class)
                    ->command('shield:integrity')
                    ->cron(config('shield.integrity.schedule.cron', '0 * * * *'))
                    ->withoutOverlapping();

                app(Schedule::class)
                    ->command('shield:integrity-prune')
                    ->daily();

                if (config('shield.integrity.heartbeat.enabled', true)) {
                    app(Schedule::class)
                        ->command('shield:integrity-heartbeat')
                        ->hourly()
                        ->withoutOverlapping();
                }
            }

            app(Schedule::class)
                ->command('shield:signatures-sync')
                ->cron(config('shield.scanner.signatures.sync_cron', '0 5 * * *'));

            app(Schedule::class)
                ->command('shield:feed-sync')
                ->cron(config('shield.threat_feed.sync_cron', '0 3 * * *'));

            // Premium realtime feed: pull deltas from Central every few minutes.
            // FeedRunner skips shield_realtime without a valid license, so this
            // schedule is a no-op on free installs.
            if (config('shield.threat_feed.shield_realtime.enabled', true)) {
                $minutes = max(1, (int) config('shield.threat_feed.shield_realtime.interval_minutes', 5));
                app(Schedule::class)
                    ->command('shield:feed-sync --source=shield_realtime')
                    ->cron($this->minutesToCron($minutes))
                    ->withoutOverlapping();
            }

            foreach ((array) config('shield.reports', []) as $cadence => $cfg) {
                if (! ($cfg['enabled'] ?? false)) continue;
                app(Schedule::class)
                    ->command("shield:report-send {$cadence}")
                    ->cron($cfg['cron_expression'] ?? '0 8 * * 1');
            }

            if (config('shield.premium.heartbeat.enabled', true)) {
                $minutes = max(1, (int) config('shield.premium.heartbeat.interval_minutes', 60));
                app(Schedule::class)
                    ->command('shield:heartbeat')
                    ->cron($this->minutesToCron($minutes));
            }

            // Background license refresh, keeps the LicenseChecker cache
            // warm so hot paths (navbar, AuditLogger) can use the cache-only
            // path without ever triggering a synchronous HTTP call to Central.
            // Fires once an hour; LicenseChecker still respects the per-state
            // freshness TTLs so this isn't a hammer.
            app(Schedule::class)
                ->command('shield:license:check')
                ->hourly()
                ->withoutOverlapping();
        });
    }

    protected function registerViews(): void
    {
        View::addNamespace('shield', __DIR__ . '/../resources/views');
    }

    /**
     * Register the @cspNonce Blade directive that emits the per-request CSP nonce.
     */
    protected function registerCspNonceBladeDirective(): void
    {
        \Illuminate\Support\Facades\Blade::directive('cspNonce', function () {
            return "<?php echo app(\\OzanKurt\\Shield\\Support\\CspNonce::class)->get(); ?>";
        });
    }

    /**
     * Register honeypot routes that auto-block on hit. Catches scanner probes
     * like /wp-admin, /.env, /phpmyadmin etc.
     *
     * Each configured path registers TWO routes:
     *   1. Exact match , /wp-admin
     *   2. Subpath match, /wp-admin/{any} where {any} matches anything
     *
     * Both fire the same controller. Subpath match catches probes like
     * /wp-admin/install.php, /.git/HEAD, /phpmyadmin/scripts/setup.php.
     */
    protected function registerHoneypotRoutes(Router $router): void
    {
        if (! config('shield.honeypot.enabled', false)) {
            return;
        }

        $controller = \OzanKurt\Shield\Http\Controllers\HoneypotController::class;
        $paths = (array) config('shield.honeypot.paths', []);

        foreach ($paths as $path) {
            $trimmed = ltrim($path, '/');

            // Exact path
            $router->any('/' . $trimmed, [$controller, 'trap']);

            // Subpath wildcard, /<path>/<anything>
            $router->any('/' . $trimmed . '/{any}', [$controller, 'trap'])
                ->where('any', '.*');
        }
    }

    /**
     * Register Laravel-native rate limiters with sensible defaults for common
     * sensitive routes. Users apply via `throttle:shield_login` etc.
     */
    protected function registerPreconfiguredRateLimiters(): void
    {
        $limiters = (array) config('shield.rate_limiters', []);
        if (empty($limiters)) return;

        foreach ($limiters as $name => $cfg) {
            if (empty($cfg['enabled'])) continue;

            \Illuminate\Support\Facades\RateLimiter::for('shield_' . $name, function ($request) use ($cfg) {
                $by = $cfg['by'] ?? 'ip';
                $key = match ($by) {
                    'user' => optional($request->user())->id ?: $request->ip(),
                    'user|ip' => optional($request->user())->id ?: $request->ip(),
                    'ip|email' => ($request->input('email') ?: '') . '|' . $request->ip(),
                    default => $request->ip(),
                };
                return \Illuminate\Cache\RateLimiting\Limit::perMinutes(
                    (int) ($cfg['decay'] ?? 60) / 60,
                    (int) ($cfg['attempts'] ?? 60),
                )->by($key);
            });
        }
    }

    /**
     * If the host application has Spatie Media Library installed, attach our
     * MediaScanListener to the Media model's `saving` event so uploads get
     * scanned before persistence.
     */
    protected function registerSpatieMediaLibraryIntegration(): void
    {
        if (! class_exists(\Spatie\MediaLibrary\MediaCollections\Models\Media::class)) {
            return;
        }

        if (! config('shield.scanner.spatie_media_library.enabled', true)) {
            return;
        }

        $listener = $this->app->make(\OzanKurt\Shield\Integrations\SpatieMediaLibrary\MediaScanListener::class);

        \Spatie\MediaLibrary\MediaCollections\Models\Media::saving(function ($media) use ($listener) {
            $listener->saving($media);
        });
    }

    /**
     * Turn an interval in minutes into a cron expression. Only intervals
     * that evenly divide 60 produce a uniform schedule. For non-divisors
     * we round UP to the next divisor, the cron syntax has no way to
     * express "every 7 minutes" without drift at the hour boundary, and
     * users typically prefer a slightly slower-but-uniform schedule over
     * an irregular one (e.g. 7→10 minutes, 25→30).
     *
     * Examples: 60 → "0 * * * *", 15 → "0,15,30,45 * * * *", 1 → "* * * * *",
     * 7 minutes → uses 10 (step), 25 minutes → uses 30 (step).
     */
    protected function minutesToCron(int $minutes): string
    {
        if ($minutes >= 60) {
            return '0 * * * *';
        }

        if ($minutes <= 1) {
            return '* * * * *';
        }

        // Find the smallest divisor of 60 that is >= $minutes.
        // 60's divisors: 1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60.
        $divisors = [1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60];
        $chosen = 60;
        foreach ($divisors as $d) {
            if ($d >= $minutes) {
                $chosen = $d;
                break;
            }
        }

        return "*/{$chosen} * * * *";
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
