<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

/**
 * Interactive ACL management. Manual bans use source='manual', which is in
 * shield.reactions.self_detected_sources, so the AclObserver fires the
 * reaction layer (Cloudflare/AbuseIPDB) automatically. Unbans soft-delete the
 * row; shield:reactions-reconcile then removes any edge rule.
 */
class AclManageCommand extends Command
{
    protected $signature = 'shield:acl
        {--list : List active blocks and exit}
        {--ban= : Ban this IP non-interactively}
        {--unban= : Unban this IP non-interactively}
        {--hours=24 : Ban duration in hours (0 = permanent), used with --ban}';

    protected $description = 'Manage the Shield ACL (list / ban / unban / search / stats)';

    public function handle(LookupResolver $lookups): int
    {
        if ($ip = $this->option('ban')) {
            $this->ban($lookups, $ip, (int) $this->option('hours'));

            return self::SUCCESS;
        }

        if ($ip = $this->option('unban')) {
            $this->unban($ip);

            return self::SUCCESS;
        }

        if ($this->option('list')) {
            $this->listActive();

            return self::SUCCESS;
        }

        return $this->menu($lookups);
    }

    private function menu(LookupResolver $lookups): int
    {
        if (! $this->input->isInteractive() || $this->option('no-interaction')) {
            $this->error('shield:acl requires an interactive terminal, or pass --list, --ban=IP, or --unban=IP.');

            return self::FAILURE;
        }

        while (true) {
            $choice = $this->choice('Shield ACL', ['List', 'Ban', 'Unban', 'Search', 'Stats', 'Quit'], 0);

            match ($choice) {
                'List' => $this->listActive(),
                'Ban' => $this->ban($lookups, $this->ask('IP to ban'), $this->durationHours()),
                'Unban' => $this->unban($this->ask('IP to unban')),
                'Search' => $this->search($this->ask('IP to search')),
                'Stats' => $this->stats(),
                'Quit' => null,
            };

            if ($choice === 'Quit') {
                return self::SUCCESS;
            }
        }
    }

    private function durationHours(): int
    {
        return (int) $this->choice('Duration', ['1', '24', '168', '720', '0'], 1);
    }

    private function ban(LookupResolver $lookups, ?string $ip, int $hours): void
    {
        if (! $ip || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error('Invalid IP.');

            return;
        }

        Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => $ip,
            'source' => 'manual',
            'reason' => 'Manual ban via shield:acl',
            'expires_at' => $hours > 0 ? now()->addHours($hours) : null,
        ]);

        $this->info("Banned {$ip}" . ($hours > 0 ? " for {$hours}h." : ' permanently.'));
    }

    private function unban(?string $ip): void
    {
        if (! $ip) {
            $this->error('No IP given.');

            return;
        }

        $count = Acl::query()->ofKind('ip')->where('value', $ip)->get()->each->delete()->count();
        $this->info("Removed {$count} ACL entr(ies) for {$ip}.");
    }

    private function search(?string $ip): void
    {
        $rows = Acl::query()->where('value', $ip)->get();
        $this->renderTable($rows);
    }

    private function listActive(): void
    {
        $rows = Acl::query()->active()->ofAction('block')->latest()->limit(50)->get();
        $this->renderTable($rows);
    }

    private function stats(): void
    {
        $bySource = Acl::query()->selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source');
        $this->table(['Source', 'Count'], $bySource->map(fn ($c, $s) => [$s, $c])->values()->all());
    }

    private function renderTable($rows): void
    {
        $this->table(
            ['IP', 'Source', 'Reason', 'Expires', 'Edge rule?'],
            $rows->map(fn (Acl $a) => [
                $a->value,
                $a->source,
                \Illuminate\Support\Str::limit((string) $a->reason, 40),
                $a->expires_at?->toDateTimeString() ?? 'never',
                empty($a->meta['reactions']['cloudflare']['rule_id']) ? 'no' : 'yes',
            ])->all()
        );
    }
}
