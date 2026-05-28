<?php

namespace OzanKurt\Shield\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isPremium()
 * @method static bool isFeatureAvailable(string $feature)
 * @method static array licenseState()
 * @method static string correlationId()
 * @method static \voku\helper\AntiXSS getAntiXss()
 * @method static string|array cleanInput(string|array $input)
 *
 * @see \OzanKurt\Shield\Shield
 */
class Shield extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'shield';
    }
}
