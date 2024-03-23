<?php

namespace OzanKurt\Security\Firewall\Traits;

use Illuminate\Http\Request;
use Jenssegers\Agent\Agent as Parser;
use OzanKurt\Security\Enums\IpEntryType;
use OzanKurt\Security\Enums\LogLevel;
use OzanKurt\Security\Models\Log;
use Symfony\Component\HttpFoundation\IpUtils;

trait Helper
{
    public string $reason = 'access_denied';
    public Request|string|array|null $request = null;
    public ?string $middleware = null;
    public ?int $user_id = null;

    public function isEnabled($middleware = null)
    {
        $middleware = $middleware ?? $this->middleware;

        return config("security.middleware.{$middleware}.enabled", config('security.enabled', false));
    }

    public function isDisabled($middleware = null)
    {
        return !$this->isEnabled($middleware);
    }

    public function isWhitelist()
    {
        if (request()->has('_debug')) {
            return false;
        }

        return IpUtils::checkIp($this->ip(), config('security.whitelist', []));
    }

    public function isMethod($middleware = null)
    {
        $middleware = $middleware ?? $this->middleware;

        if (!$methods = config("security.middleware.{$middleware}.methods")) {
            return false;
        }

        if (in_array('all', $methods)) {
            return true;
        }

        return in_array(strtolower($this->request->method()), $methods);
    }

    public function isRoute($middleware = null)
    {
        $middleware = $middleware ?? $this->middleware;

        if (!$routes = config("security.middleware.{$middleware}.routes")) {
            return false;
        }

        foreach ($routes['except'] as $ex) {
            if (!$this->request->is($ex)) {
                continue;
            }

            return true;
        }

        foreach ($routes['only'] as $on) {
            if ($this->request->is($on)) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function isInput($name, $middleware = null)
    {
        $middleware = $middleware ?? $this->middleware;

        if (!$inputs = config("security.middleware.{$middleware}.inputs")) {
            return true;
        }

        if (!empty($inputs['only']) && !in_array((string)$name, (array)$inputs['only'])) {
            return false;
        }

        return !in_array((string)$name, (array)$inputs['except']);
    }

    public function log(
        ?string  $middleware = null,
        ?int     $user_id = null,
        LogLevel $level = LogLevel::MEDIUM
    ): Log
    {
        $middleware = $middleware ?? $this->middleware;
        $user_id = $user_id ?? $this->user_id;

        $model = config('security.database.log.model', Log::class);

        return $model::create([
            'user_id' => $user_id,
            'middleware' => $middleware,
            'level' => $level,
            'ip' => $this->ip(),
            'url' => $this->request->fullUrl(),
            'user_agent' => $this->getUserAgent(),
            'referrer' => substr($this->request->server('HTTP_REFERER'), 0, 191) ?: null,
            'request_data' => $this->getRequestData(),
        ]);
    }

    public function getUserAgent(): ?string
    {
        $parser = new Parser();

        return $parser->getUserAgent();
    }

    public function getRequestData(): ?array
    {
        $requestData = $this->request->input();
        $requestDataJson = json_encode($requestData);

        $maxSize = config('security.database.log.max_request_data_size', 2048);
        $size = mb_strlen($requestDataJson, '8bit');

        if ($size > $maxSize) {
            $requestData = [
                'message' => 'Request data has been deleted.',
                'size' => $size,
            ];
        }

        return $requestData;
    }

    public function ip()
    {
        if ($cf_ip = $this->request->header('CF_CONNECTING_IP')) {
            $ip = $cf_ip;
        } else {
            $ip = $this->request->ip();
        }

        return $ip;
    }
}
