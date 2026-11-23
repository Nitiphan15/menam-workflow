<?php

namespace App\Services\FormDP;

use RuntimeException;

/**
 * Thrown locally (no HTTP sent) when another request would exceed DTC's
 * "more than 3 requests per minute from the same IP" rule (HTTP 429).
 */
class DtcRateLimitException extends RuntimeException
{
    public int $retryAfterSeconds;

    public function __construct(int $retryAfterSeconds)
    {
        $this->retryAfterSeconds = max(1, $retryAfterSeconds);
        parent::__construct('DTC per-minute request limit reached locally.');
    }
}
