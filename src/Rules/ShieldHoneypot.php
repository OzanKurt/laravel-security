<?php

namespace OzanKurt\Shield\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Crypt;
use OzanKurt\Shield\Services\Scoring\SuspicionScorer;

/**
 * Validation-rule equivalent of ProtectAgainstSpam, for Livewire / manual
 * forms. Attach to the honeypot field: 'shield_hp' => [new ShieldHoneypot].
 * The field under validation must be empty; the companion timestamp field is
 * read from the current request.
 */
class ShieldHoneypot implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (filled($value)) {
            $this->escalateAndFail($fail);

            return;
        }

        if (! config('shield.honeypot.form.require_timestamp', true)) {
            return;
        }

        $timeField = (string) config('shield.honeypot.form.valid_from_field', 'shield_hp_time');
        $raw = request()->input($timeField);

        try {
            $payload = json_decode(Crypt::decryptString((string) $raw), true);
        } catch (\Throwable) {
            $this->escalateAndFail($fail);

            return;
        }

        // The trap field name ('n') is ignored here, the rule validates its own
        // developer-named attribute; only the timestamp ('t') is needed.
        if (! is_array($payload) || ! isset($payload['t'])) {
            $this->escalateAndFail($fail);

            return;
        }

        $submittedAt = (int) $payload['t'];
        $elapsed = now()->timestamp - $submittedAt;
        $min = (int) config('shield.honeypot.form.min_time_seconds', 1);
        $max = (int) config('shield.honeypot.form.max_time_seconds', 3600);

        if ($elapsed < $min || $elapsed > $max) {
            $this->escalateAndFail($fail);
        }
    }

    private function escalateAndFail(Closure $fail): void
    {
        app(SuspicionScorer::class)->bump(request()->ip(), (int) config('shield.honeypot.form.score', 50));
        $fail('The given data was invalid.');
    }
}
