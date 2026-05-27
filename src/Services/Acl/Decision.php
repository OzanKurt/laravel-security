<?php

namespace OzanKurt\Shield\Services\Acl;

use OzanKurt\Shield\Models\Acl;

class Decision
{
    public function __construct(
        public readonly string $action,        // 'allow' | 'block' | 'blacklist' | 'pass'
        public readonly ?Acl $matchedEntry = null,
    ) {}

    public static function allow(Acl $entry): self
    {
        return new self('allow', $entry);
    }

    public static function blacklist(Acl $entry): self
    {
        return new self('blacklist', $entry);
    }

    public static function block(Acl $entry): self
    {
        return new self('block', $entry);
    }

    public static function pass(): self
    {
        return new self('pass');
    }

    public function isDeny(): bool
    {
        return in_array($this->action, ['block', 'blacklist'], true);
    }
}
