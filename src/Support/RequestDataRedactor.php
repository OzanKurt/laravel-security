<?php

namespace OzanKurt\Shield\Support;

/**
 * Redact sensitive fields from request data before persistence.
 *
 * Config:
 *   shield.redaction.keys         — list of key patterns (supports wildcards)
 *   shield.redaction.placeholder  — replacement value (default '[redacted]')
 *   shield.redaction.use_regex    — when true, '*' in a key is treated as ".*" regex
 */
class RequestDataRedactor
{
    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->walk($value);
        }
        return $value;
    }

    private function walk(array $data): array
    {
        $patterns = (array) config('shield.redaction.keys', $this->defaultKeys());
        $placeholder = (string) config('shield.redaction.placeholder', '[redacted]');
        $useRegex = (bool) config('shield.redaction.use_regex', true);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->walk($value);
                continue;
            }

            if ($this->keyMatches((string) $key, $patterns, $useRegex)) {
                $data[$key] = $placeholder;
            }
        }

        return $data;
    }

    private function keyMatches(string $key, array $patterns, bool $useRegex): bool
    {
        $lower = strtolower($key);
        foreach ($patterns as $pattern) {
            $patternLower = strtolower((string) $pattern);

            if ($useRegex && str_contains($patternLower, '*')) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($patternLower, '/')) . '$/i';
                if (preg_match($regex, $lower)) {
                    return true;
                }
                continue;
            }

            if ($lower === $patternLower) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private function defaultKeys(): array
    {
        return [
            'password', 'password_confirmation', 'old_password', 'new_password',
            'token', 'api_key', 'secret', '*_token', '*_secret', '*_key',
            'authorization', 'cookie',
            'credit_card', 'card_number', 'cvv', 'cvc',
            'ssn', 'social_security_number',
        ];
    }
}
