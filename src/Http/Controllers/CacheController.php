<?php

namespace OzanKurt\Shield\Http\Controllers;

use Illuminate\Support\Facades\Cache;

class CacheController extends \Illuminate\Routing\Controller
{
    public function index()
    {
        $rows = collect($this->knownKeys())->map(fn ($key) => [
            'key' => $key,
            'present' => Cache::has($key),
        ])->toArray();

        return view('shield::dashboard.cache.index', ['rows' => $rows]);
    }

    public function clear()
    {
        $key = request('key');

        if ($key === '*' || $key === null) {
            foreach ($this->knownKeys() as $k) {
                Cache::forget($k);
            }
            return response()->json(['ok' => true, 'cleared' => 'all']);
        }

        Cache::forget($key);
        return response()->json(['ok' => true, 'cleared' => $key]);
    }

    /** @return string[] */
    private function knownKeys(): array
    {
        return [
            'shield.acl.live',
            'shield.lookups.AclKind',
            'shield.lookups.AclAction',
            'shield.lookups.LogLevel',
            'shield.lookups.LogKind',
            'shield.lookups.AuditLogKind',
            'shield.lookups.AuthEventKind',
            'shield.lookups.ActionKind',
            'shield.lookups.WafRuleCategory',
            'shield.lookups.WafRuleKind',
            'shield.lookups.WafRuleTarget',
            'shield.lookups.WafRuleAction',
            'shield.waf.rules.xss',
            'shield.waf.rules.sqli',
            'shield.waf.rules.lfi',
            'shield.waf.rules.rfi',
            'shield.waf.rules.php_protocols',
            'shield.waf.rules.session',
            'shield.waf.rules.keyword',
            'shield.waf.rules.custom',
            'shield.waf.rules.agent',
            'shield.waf.rules.bot',
        ];
    }
}
