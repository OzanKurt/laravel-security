<?php

namespace OzanKurt\Shield\Services\Reactions;

use OzanKurt\Shield\Contracts\AclReaction;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class CloudflareReaction implements AclReaction
{
    public function __construct(
        private CloudflareClient $client,
        private LookupResolver $lookups,
    ) {}

    public function name(): string
    {
        return 'cloudflare';
    }

    public function isEnabled(): bool
    {
        return (bool) config('shield.reactions.cloudflare.enabled', false)
            && $this->client->isConfigured();
    }

    public function appliesTo(Acl $acl): bool
    {
        if ($acl->kind_id !== $this->lookups->id(AclKind::class, 'ip')) {
            return false;
        }
        if ($acl->action_id !== $this->lookups->id(AclAction::class, 'block')) {
            return false;
        }

        return $this->isPublicIp((string) $acl->value);
    }

    public function ban(Acl $acl): void
    {
        if (! empty($acl->meta['reactions']['cloudflare']['rule_id'])) {
            return; // already pushed
        }

        $note = config('shield.reactions.cloudflare.note_category', 'shield-block')
            . ': ' . (string) $acl->reason;

        $ruleId = $this->client->createBlockRule((string) $acl->value, $note);

        if ($ruleId === null) {
            throw new \RuntimeException('Cloudflare rule create failed (will retry)');
        }

        $this->setMeta($acl, ['rule_id' => $ruleId, 'created_at' => now()->toIso8601String()]);
    }

    public function unban(Acl $acl): void
    {
        $ruleId = $acl->meta['reactions']['cloudflare']['rule_id'] ?? null;
        if ($ruleId === null) {
            return;
        }

        if (! $this->client->deleteRule((string) $ruleId)) {
            throw new \RuntimeException('Cloudflare rule delete failed (will retry)');
        }

        $this->clearMeta($acl);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function setMeta(Acl $acl, array $data): void
    {
        $meta = $acl->meta ?? [];
        $meta['reactions']['cloudflare'] = $data;
        $acl->update(['meta' => $meta]);
    }

    private function clearMeta(Acl $acl): void
    {
        $meta = $acl->meta ?? [];
        unset($meta['reactions']['cloudflare']);
        $acl->update(['meta' => $meta]);
    }
}
