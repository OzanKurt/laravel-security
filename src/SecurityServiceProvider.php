<?php

namespace OzanKurt\Security;

use OzanKurt\Security\Commands\UnblockIpsCommand;
use OzanKurt\Security\Commands\SendReportMailCommand;
use OzanKurt\Security\Events\AttackDetected;
use OzanKurt\Security\Listeners\BlockIp;
use OzanKurt\Security\Listeners\CheckLogin;
use OzanKurt\Security\Listeners\NotifyUsers;
use Illuminate\Auth\Events\Authenticated as LoginAuthenticated;
use Illuminate\Auth\Events\Failed as LoginFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class SecurityServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/security.php', 'security');

        $this->app->register(\Jenssegers\Agent\AgentServiceProvider::class);
    }

    /**
     * Bootstrap the application services.
     *
     * @param Router $router
     *
     * @return void
     */
    public function boot(Router $router)
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
    }

    /**
     * Register middleware.
     *
     * @param Router $router
     *
     * @return void
     */
    public function registerMiddleware($router)
    {
        $router->middlewareGroup('firewall.all', config('security.all_middleware'));
        $router->aliasMiddleware('firewall.agent', \OzanKurt\Security\Middleware\Agent::class);
        $router->aliasMiddleware('firewall.bot', \OzanKurt\Security\Middleware\Bot::class);
        $router->aliasMiddleware('firewall.ip', \OzanKurt\Security\Middleware\Ip::class);
        $router->aliasMiddleware('firewall.geo', \OzanKurt\Security\Middleware\Geo::class);
        $router->aliasMiddleware('firewall.lfi', \OzanKurt\Security\Middleware\Lfi::class);
        $router->aliasMiddleware('firewall.php', \OzanKurt\Security\Middleware\Php::class);
        $router->aliasMiddleware('firewall.referrer', \OzanKurt\Security\Middleware\Referrer::class);
        $router->aliasMiddleware('firewall.rfi', \OzanKurt\Security\Middleware\Rfi::class);
        $router->aliasMiddleware('firewall.session', \OzanKurt\Security\Middleware\Session::class);
        $router->aliasMiddleware('firewall.sqli', \OzanKurt\Security\Middleware\Sqli::class);
        $router->aliasMiddleware('firewall.swear', \OzanKurt\Security\Middleware\Swear::class);
        $router->aliasMiddleware('firewall.url', \OzanKurt\Security\Middleware\Url::class);
        $router->aliasMiddleware('firewall.whitelist', \OzanKurt\Security\Middleware\Whitelist::class);
        $router->aliasMiddleware('firewall.xss', \OzanKurt\Security\Middleware\Xss::class);
        $router->aliasMiddleware('firewall.keyword', \OzanKurt\Security\Middleware\Keyword::class);
    }

    /**
     * Register listeners.
     *
     * @return void
     */
    public function registerListeners()
    {
        $this->app['events']->listen(AttackDetected::class, BlockIp::class);
        $this->app['events']->listen(AttackDetected::class, NotifyUsers::class);
        $this->app['events']->listen(LoginAuthenticated::class, CheckLogin::class);
        $this->app['events']->listen(LoginFailed::class, CheckLogin::class);
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations($langPath)
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'security');

        $this->loadTranslationsFrom($langPath, 'security');
    }

    public function registerCommands()
    {
        $this->commands(UnblockIpsCommand::class);
        $this->commands(SendReportMailCommand::class);

        if (config('security.cron.enabled')) {
            $this->app->booted(function () {
                app(Schedule::class)->command('security:unblock-ips')->cron(config('security.cron.expression'));
            });
        }
    }

    public function getMigrationPathFor(string $modelKey): string
    {
        $prefix = date('Y_m_d').'_000000';
        $tableName = $this->getNameTable($modelKey);

        return database_path("migrations/{$prefix}_create_{$tableName}_table.php");
    }

    public function getNameTable(string $modelKey): string
    {
        $tablePrefix = config('security.database.table_prefix', 'sec_');
        $tableName = config("security.database.{$modelKey}.table", $modelKey);

        return $tablePrefix.$tableName;
    }
}
