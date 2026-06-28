<?php

namespace OzanKurt\Shield\View\Components;

use Illuminate\Support\Facades\Crypt;
use Illuminate\View\Component;

class Honeypot extends Component
{
    public string $nameField;
    public string $timeField;
    public string $token;

    public function __construct()
    {
        $this->nameField = (string) config('shield.honeypot.form.name_field', 'shield_hp');
        $this->timeField = (string) config('shield.honeypot.form.valid_from_field', 'shield_hp_time');
        $this->token = Crypt::encryptString((string) now()->timestamp);
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
