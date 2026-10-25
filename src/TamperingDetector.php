<?php

namespace MonkeySoft\LivewireShield;

use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Throwable;
use TypeError;

/**
 * Decides whether an exception is the result of a hand-edited Livewire payload.
 *
 * The three Livewire exceptions are unambiguous. A bare TypeError is not: it is also what a
 * real bug looks like, so it only counts when it was thrown while Livewire was hydrating an
 * incoming property update (e.g. Filament's notifications receiving `[1]` instead of arrays).
 */
class TamperingDetector
{
    public static function isTampering(Throwable $exception): bool
    {
        if ($exception instanceof CannotUpdateLockedPropertyException
            || $exception instanceof CorruptComponentPayloadException
            || $exception instanceof PublicPropertyNotFoundException) {
            return true;
        }

        return $exception instanceof TypeError && self::thrownWhileHydratingUpdate($exception);
    }

    private static function thrownWhileHydratingUpdate(TypeError $exception): bool
    {
        foreach ($exception->getTrace() as $frame) {
            if (($frame['class'] ?? null) === HandleComponents::class
                && ($frame['function'] ?? null) === 'updateProperties') {
                return true;
            }
        }

        return false;
    }
}
