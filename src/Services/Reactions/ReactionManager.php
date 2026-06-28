<?php

namespace OzanKurt\Shield\Services\Reactions;

use OzanKurt\Shield\Contracts\AclReaction;
use OzanKurt\Shield\Jobs\RunAclReactionJob;
use OzanKurt\Shield\Models\Acl;

class ReactionManager
{
    /** @param array<int,AclReaction> $reactions */
    public function __construct(private array $reactions = []) {}

    /** @return array<int,AclReaction> */
    public function reactions(): array
    {
        return $this->reactions;
    }

    public function get(string $name): ?AclReaction
    {
        foreach ($this->reactions as $reaction) {
            if ($reaction->name() === $name) {
                return $reaction;
            }
        }

        return null;
    }

    public function onBlock(Acl $acl): void
    {
        if (! $this->sourceAllowed($acl)) {
            return;
        }

        foreach ($this->reactions as $reaction) {
            if ($reaction->isEnabled() && $reaction->appliesTo($acl)) {
                RunAclReactionJob::dispatch($reaction->name(), $acl->getKey(), 'ban')->afterCommit();
            }
        }
    }

    public function onUnblock(Acl $acl): void
    {
        foreach ($this->reactions as $reaction) {
            if ($reaction->isEnabled()) {
                RunAclReactionJob::dispatch($reaction->name(), $acl->getKey(), 'unban')->afterCommit();
            }
        }
    }

    public function sourceAllowed(Acl $acl): bool
    {
        $allowed = (array) config('shield.reactions.self_detected_sources', []);

        return in_array($acl->source, $allowed, true);
    }
}
