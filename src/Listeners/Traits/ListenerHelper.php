<?php

namespace OzanKurt\Shield\Listeners\Traits;

use Illuminate\Http\Request;
use OzanKurt\Shield\Helpers\Helper;
use OzanKurt\Shield\Models\AuthLog;
use RuntimeException;

trait ListenerHelper
{
    use Helper;

    public ?string $notification = null;
    public ?string $middleware = null;
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
        return config("shield.middleware.{$this->middleware}.enabled", false);
    }

    public function isNotificationEnabled()
    {
        if (! $this->notification) {
            throw new RuntimeException("The notification [{$this->notification}] is not configured in the `config/shield.php` file.");
        }

        return config("shield.notifications.{$this->notification}.enabled", false);
    }

    public function authLog(
        bool $isSuccessful,
        ?int $user_id = null,
    ): ?AuthLog
    {
        $user_id = $user_id ?? $this->user_id;

        $model = config('shield.database.auth_log.model', AuthLog::class);

        $email = $this->request->input('email');

        if (! $email) {
            return null;
        }

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
