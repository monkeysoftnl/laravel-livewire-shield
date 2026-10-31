<?php

namespace MonkeySoft\LivewireShield;

use Throwable;

/**
 * Kept for 1.x callers; the classification itself lives in ExceptionCategory.
 */
class TamperingDetector
{
    public static function isTampering(Throwable $exception): bool
    {
        return ExceptionCategory::classify($exception) === ExceptionCategory::Tampering;
    }
}
