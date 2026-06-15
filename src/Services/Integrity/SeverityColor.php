<?php

namespace OzanKurt\Shield\Services\Integrity;

/**
 * Maps a severity name to a hex colour, shared by all notification channels so
 * the integrity card reads consistently across mail / Slack / Discord.
 */
class SeverityColor
{
    public const MAP = [
        'critical' => '8b0000',  // dark red
        'high' => 'e32929',      // red
        'medium' => 'fd6a02',    // orange
        'low' => '2b6cb0',       // blue
        'all_clear' => '0b6623', // green
        'info' => '6c757d',      // gray
    ];

    public const DEFAULT = '6c757d';

    public static function hex(string $severity): string
    {
        return self::MAP[$severity] ?? self::DEFAULT;
    }
}
