<?php

namespace OzanKurt\Security\Listeners\Traits;

use Illuminate\Http\Request;
use OzanKurt\Agent\Agent as Parser;
use OzanKurt\Security\Helpers\Helper;
use OzanKurt\Security\Models\AuthLog;

trait ListenerHelper
{
    use Helper;

    public ?string $notification = null;
    public Request|string|array|null $request = null;
    public ?int $user_id = null;
    public ?array $meta = null;

    public function skip(): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        return false;
    }

    public function isEnabled()
    {
        if (! $this->notification) {
            throw new Exception("The notification [{$this->notification}] is not configured in the `config/security.php` file.");
        }

        return config("security.notifications.{$this->notification}.enabled", false);
    }

    public function authLog(
        bool $isSuccessful,
        ?int $user_id = null,
    ): Log
    {
        $user_id = $user_id ?? $this->user_id;

        $model = config('security.database.auth_log.model', AuthLog::class);

        return $model::create([
            'email' => $this->request->input('email'),
            'is_successful' => $isSuccessful,
            'user_id' => $user_id,
            'ip' => $this->ip(),
            'user_agent' => $this->getUserAgent(),
            'referrer' => substr($this->request->server('HTTP_REFERER'), 0, 191) ?: null,
            'request_data' => $this->getRequestData(),
            'meta_data' => $this->meta,
        ]);
    }
}
