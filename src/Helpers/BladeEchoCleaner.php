<?php

namespace OzanKurt\Security\Helpers;

class BladeEchoCleaner
{
    protected $bladeEchoPatterns = [];

    /**
     * @see Illuminate\View\Compilers\Concerns\CompilesEchos::compileRawEchos
     */
    public function __construct()
    {
        $bladeEchoTags = config('security.middleware.xss.blade_echo_tags', []);

        foreach ($bladeEchoTags as $pair) {
            $this->bladeEchoPatterns[] = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $pair[0], $pair[1]);
        }

        usort($this->bladeEchoPatterns, fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    public function clean(string $value): string
    {
        foreach ($this->bladeEchoPatterns as $pattern) {
            if (preg_match($pattern, $value, $matches)) {
                return str_replace($matches[0], '', $value);
            }
        }

        return $value;
    }
}
