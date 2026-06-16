<?php

namespace OzanKurt\Shield\Services\Integrity;

use RuntimeException;

/**
 * Thrown when a baseline artifact exists and parses but its HMAC signature does
 * not verify. This is a SECURITY signal (someone rewrote the approved baseline),
 * distinct from benign corruption. The scanner must NOT auto-rebless on this.
 */
class BaselineTamperException extends RuntimeException
{
}
