<?php

namespace OzanKurt\Shield\Services\Waf;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use OzanKurt\Shield\Models\Lookups\WafRuleCategory;
use OzanKurt\Shield\Models\WafRule;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class WafRuleResolver
{
    public function __construct(private LookupResolver $lookups) {}

    public function forCategory(string $category): Collection
    {
        $categoryId = $this->lookups->id(WafRuleCategory::class, $category);
        if ($categoryId === null) {
            return collect();
        }

        return Cache::rememberForever(
            'shield.waf.rules.' . $category,
            fn () => WafRule::query()
                ->where('category_id', $categoryId)
                ->enabled()
                ->orderBy('id')
                ->get(),
        );
    }

    public function clearCache(?string $category = null): void
    {
        if ($category) {
            Cache::forget('shield.waf.rules.' . $category);
            return;
        }
        // Clear all known categories
        foreach ($this->lookups->all(WafRuleCategory::class) as $name => $id) {
            Cache::forget('shield.waf.rules.' . $name);
        }
    }
}
