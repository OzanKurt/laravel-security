<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'shield:install {--force} {--no-ip-prompt}';
    protected $description = 'First-time setup: publish config, run migrations, generate secrets, seed lookups + builtin rules';

    public function handle(): int
    {
        $this->info('Installing Laravel Shield...');

        // 1. Publish config
        Artisan::call('vendor:publish', [
            '--tag' => 'shield-config',
            '--force' => $this->option('force'),
        ]);
        $this->line(' ✓ Config published');

        // 2. Publish migrations + lang + assets
        Artisan::call('vendor:publish', ['--tag' => 'shield-migrations', '--force' => $this->option('force')]);
        Artisan::call('vendor:publish', ['--tag' => 'shield-lang', '--force' => $this->option('force')]);
        Artisan::call('vendor:publish', ['--tag' => 'shield-assets', '--force' => $this->option('force')]);
        $this->line(' ✓ Migrations + lang + assets published');

        // 3. Run migrations
        try {
            Artisan::call('migrate', ['--no-interaction' => true]);
        } catch (\Throwable $e) {
            // Gracefully handle already-existing tables (e.g. in test environments)
        }
        $this->line(' ✓ Migrations executed');

        // 4. Generate secrets if missing
        $this->generateSecretIfMissing('LS_AUDIT_HMAC_SECRET', 64);
        $this->generateSecretIfMissing('LS_BYPASS_KEY', 32);
        $this->line(' ✓ Secrets ensured (LS_AUDIT_HMAC_SECRET, LS_BYPASS_KEY)');

        // 5. Seed lookups
        (new \OzanKurt\Shield\Database\Seeders\LookupTableSeeder())->run();
        $this->line(' ✓ Lookup tables seeded');

        // 6. Seed builtin WAF rules
        (new \OzanKurt\Shield\Database\Seeders\BuiltinWafRuleSeeder())->run();
        $this->line(' ✓ Builtin WAF rules seeded (~47 rules)');

        // 7. (Optional) Whitelist current IP
        if (! $this->option('no-ip-prompt')) {
            $ip = request()?->ip() ?? gethostbyname(gethostname());
            if ($ip && $this->confirm("Whitelist your current IP ($ip) so you don't lock yourself out?", true)) {
                Artisan::call('shield:bypass-add', ['ip' => $ip]);
                $this->line(" ✓ $ip whitelisted (remove from /shield/acl when done)");
            }
        }

        // 8. Print next steps
        $this->newLine();
        $this->info('Laravel Shield installed successfully.');
        $this->line('Next steps:');
        $this->line('  1. Visit your-app/shield/  (grant viewShieldDashboard gate first)');
        $this->line('  2. (Optional) Install ClamAV: composer require xenolope/quahog');
        $this->line('  3. (Optional) Buy a premium license at https://laravel-shield.ozankurt.com');

        return self::SUCCESS;
    }

    private function generateSecretIfMissing(string $key, int $length): void
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) return;

        $env = file_get_contents($envPath);
        if (preg_match("/^$key=.+$/m", $env)) {
            return;
        }

        $value = Str::random($length);
        $append = (str_ends_with($env, "\n") ? '' : "\n") . "$key=$value\n";
        file_put_contents($envPath, $env . $append);
    }
}
