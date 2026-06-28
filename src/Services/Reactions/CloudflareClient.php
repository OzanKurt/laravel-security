<?php

namespace OzanKurt\Shield\Services\Reactions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Cloudflare IP Access Rules API. Zone-scoped when a
 * zone_id is configured, otherwise account-scoped. Returns null/false on any
 * non-success so callers never see exceptions on the happy path; transient
 * failures are surfaced by returning null so the queued job can retry.
 */
class CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function isConfigured(): bool
    {
        $token = (string) config('shield.reactions.cloudflare.api_token');
        $scope = $this->scopePath();

        return $token !== '' && $scope !== null;
    }

    /** @return string|null the created rule id, or null on failure */
    public function createBlockRule(string $ip, string $note): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->http()->post($this->scopePath() . '/firewall/access_rules/rules', [
                'mode' => 'block',
                'configuration' => ['target' => 'ip', 'value' => $ip],
                'notes' => $note,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Cloudflare access rule create failed', [
                'ip' => $ip, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful() || ! $response->json('success')) {
            Log::warning('Cloudflare access rule create failed', [
                'ip' => $ip, 'status' => $response->status(),
            ]);

            return null;
        }

        return $response->json('result.id');
    }

    public function deleteRule(string $ruleId): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->http()->delete($this->scopePath() . '/firewall/access_rules/rules/' . $ruleId);
        } catch (\Throwable $e) {
            Log::warning('Cloudflare access rule delete failed', [
                'rule_id' => $ruleId, 'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $response->successful() && (bool) $response->json('success');
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(20)
            ->withToken((string) config('shield.reactions.cloudflare.api_token'))
            ->acceptJson();
    }

    /** Returns the base path segment for zone- or account-scoped rules, or null. */
    private function scopePath(): ?string
    {
        $zone = (string) config('shield.reactions.cloudflare.zone_id');
        if ($zone !== '') {
            return self::BASE . '/zones/' . $zone;
        }

        $account = (string) config('shield.reactions.cloudflare.account_id');
        if ($account !== '') {
            return self::BASE . '/accounts/' . $account;
        }

        return null;
    }
}
