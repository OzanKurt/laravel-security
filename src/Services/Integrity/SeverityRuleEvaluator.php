<?php

namespace OzanKurt\Shield\Services\Integrity;

/**
 * Evaluates the ordered, first-match severity rules from config against a single
 * change. Each rule's `when` is an AND of the conditions present:
 *   - path_any:        any of these globs matches the path ({public_docroot} expands)
 *   - ext_any:         the path extension is one of these
 *   - change_type_any: the change type is one of these
 *   - always:          unconditional (the default/fallback rule)
 */
class SeverityRuleEvaluator
{
    /**
     * @param  array<int,array>  $rules
     * @param  array<string,string>  $context  e.g. ['public_docroot' => 'public']
     */
    public function __construct(
        private array $rules,
        private array $context = []
    ) {}

    public function evaluate(string $path, string $changeType): string
    {
        foreach ($this->rules as $rule) {
            if ($this->matches($rule['when'] ?? [], $path, $changeType)) {
                return (string) ($rule['severity'] ?? 'low');
            }
        }

        return 'low';
    }

    private function matches(array $when, string $path, string $changeType): bool
    {
        if (! empty($when['always'])) {
            return true;
        }

        if (empty($when)) {
            return false;
        }

        if (isset($when['change_type_any']) && ! in_array($changeType, (array) $when['change_type_any'], true)) {
            return false;
        }

        if (isset($when['ext_any'])) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $exts = array_map('strtolower', (array) $when['ext_any']);
            if (! in_array($ext, $exts, true)) {
                return false;
            }
        }

        if (isset($when['path_any']) && ! Manifest::matchesAnyGlob($path, $this->expand((array) $when['path_any']))) {
            return false;
        }

        return true;
    }

    /** @param string[] $globs */
    private function expand(array $globs): array
    {
        $docroot = $this->context['public_docroot'] ?? 'public';

        return array_map(fn ($g) => str_replace('{public_docroot}', $docroot, $g), $globs);
    }
}
