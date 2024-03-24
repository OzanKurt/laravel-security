<?php

namespace OzanKurt\Security\Helpers;

use Illuminate\Http\Request;
use OzanKurt\Agent\Agent as Parser;
use OzanKurt\Security\Enums\IpEntryType;
use OzanKurt\Security\Enums\LogLevel;
use OzanKurt\Security\Models\Log;
use Symfony\Component\HttpFoundation\IpUtils;

trait Helper
{
    public function getUserAgent(): ?string
    {
        $parser = new Parser();

        return $parser->getUserAgent();
    }

    public function getRequestData(): ?array
    {
        $requestData = $this->request->input();
        $requestDataJson = json_encode($requestData);

        $maxSize = config('security.database.max_request_data_size', 2048);
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
