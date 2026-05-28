<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use OzanKurt\Shield\Services\Premium\LicenseChecker;

class LicenseController extends Controller
{
    /**
     * Render the License dashboard page. Shows current state (active/
     * grace/invalid/no-key), plan, expiry, domain limit + count, plus
     * a Buy CTA when no license is configured.
     */
    public function index(LicenseChecker $checker)
    {
        $state = $checker->state();
        $hasKey = $checker->hasKey();
        $maskedKey = $checker->maskedKey();

        return view('shield::dashboard.license.index', compact('state', 'hasKey', 'maskedKey'));
    }

    /**
     * Force a fresh check against Central, bypassing the 24h cache.
     * Used by the "Refresh now" button on the License page.
     */
    public function refresh(Request $request, LicenseChecker $checker): RedirectResponse
    {
        $state = $checker->refresh();

        $flash = match ($state['state'] ?? null) {
            'valid' => 'License refreshed successfully.',
            'grace' => 'Central unreachable — grace period active until ' . ($state['grace_until'] ?? 'unknown') . '.',
            'invalid' => 'License is invalid: ' . ($state['reason'] ?? 'unknown reason') . '.',
            'no_key' => 'No license key configured. Add LS_PREMIUM_LICENSE_KEY to .env.',
            default => 'License refresh completed.',
        };

        return redirect()
            ->route(config('shield.dashboard.route_name') . 'license.index')
            ->with('status', $flash);
    }

    /**
     * Wipe the cached license result. Next call to isPremium() will hit
     * Central. Useful for testing + force-revalidation after a key swap.
     */
    public function clear(Request $request, LicenseChecker $checker): RedirectResponse
    {
        $checker->clearCache();

        return redirect()
            ->route(config('shield.dashboard.route_name') . 'license.index')
            ->with('status', 'License cache cleared.');
    }
}
