<?php

namespace OzanKurt\Shield\Services\Integrity;

use RuntimeException;

/**
 * Thrown when a manifest walk exceeds its configured file or iteration cap.
 * The scanner catches this and records the run as status=aborted_limit rather
 * than hanging or silently truncating.
 */
class ManifestLimitException extends RuntimeException
{
}
