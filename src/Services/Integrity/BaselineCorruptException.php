<?php

namespace OzanKurt\Shield\Services\Integrity;

use RuntimeException;

/**
 * Thrown when a baseline artifact exists but cannot be decompressed or parsed
 * (e.g. a partial write from a crashed bless). Benign, but the scanner should
 * fail the run and require an explicit re-bless rather than silently rebuilding.
 */
class BaselineCorruptException extends RuntimeException
{
}
