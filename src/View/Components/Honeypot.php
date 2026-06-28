<?php

namespace OzanKurt\Shield\View\Components;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\View\Component;

class Honeypot extends Component
{
    public string $nameField;
    public string $timeField;
    public string $token;

    public function __construct()
    {
        $baseName = (string) config('shield.honeypot.form.name_field', 'shield_hp');
        $this->nameField = config('shield.honeypot.form.randomize')
            ? $baseName . '_' . Str::random(10)
            : $baseName;

        // The companion (timestamp) field name stays fixed so the middleware can
        // always locate it. The encrypted payload carries both the (possibly
        // randomized) trap field name and the timestamp.
        $this->timeField = (string) config('shield.honeypot.form.valid_from_field', 'shield_hp_time');
        $this->token = Crypt::encryptString(json_encode(['n' => $this->nameField, 't' => now()->timestamp]));
    }

    public function render()
    {
        return <<<'BLADE'
        <div style="display:none !important;" aria-hidden="true">
            <input type="text" name="{{ $nameField }}" value="" tabindex="-1" autocomplete="off" />
            <input type="text" name="{{ $timeField }}" value="{{ $token }}" tabindex="-1" autocomplete="off" />
        </div>
        BLADE;
    }
}
