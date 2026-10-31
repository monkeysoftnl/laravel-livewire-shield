<?php

namespace MonkeySoft\LivewireShield;

use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Exceptions\LivewireReleaseTokenMismatchException;
use Livewire\Exceptions\MaxNestingDepthExceededException;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Exceptions\NonPublicComponentMethodCall;
use Livewire\Exceptions\PayloadTooLargeException;
use Livewire\Exceptions\PropertyNotFoundException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Exceptions\TooManyCallsException;
use Livewire\Exceptions\TooManyComponentsException;
use Livewire\Features\SupportComputed\CannotCallComputedDirectlyException;
use Livewire\Features\SupportFileUploads\MissingFileUploadsTraitException;
use Livewire\Features\SupportLifecycleHooks\DirectlyCallingLifecycleHooksNotAllowedException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportReactiveProps\CannotMutateReactivePropException;
use Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use MonkeySoft\LivewireShield\Exceptions\PayloadLimitExceeded;
use Throwable;
use TypeError;

/**
 * The kinds of Livewire failure Shield takes over. Anything that classifies as null is left
 * exactly as Laravel and Livewire handle it: validation, authentication, authorization, CSRF,
 * missing models and real bugs keep their own responses and their own reporting.
 *
 * Some classes only exist in one Livewire major; `instanceof` against a missing class is
 * simply false, so the lists can name both.
 */
enum ExceptionCategory: string
{
    /** A hand-edited payload: counted towards the per-address limit. */
    case Tampering = 'tampering';

    /** The request exceeded a payload guard (Livewire 4's own, or Shield's on Livewire 3). */
    case PayloadLimit = 'payload_limit';

    /** The page was rendered by an older deployment; the client should reload. */
    case StaleComponent = 'stale_component';

    private const TAMPERING = [
        CannotUpdateLockedPropertyException::class,
        CorruptComponentPayloadException::class,
        PublicPropertyNotFoundException::class,
        PropertyNotFoundException::class,
        MissingFileUploadsTraitException::class,
        MethodNotFoundException::class,
        NonPublicComponentMethodCall::class,
        DirectlyCallingLifecycleHooksNotAllowedException::class,
        CannotCallComputedDirectlyException::class,
        CannotMutateReactivePropException::class,
    ];

    private const PAYLOAD_LIMIT = [
        PayloadLimitExceeded::class,
        PayloadTooLargeException::class,
        MaxNestingDepthExceededException::class,
        TooManyCallsException::class,
        TooManyComponentsException::class,
    ];

    private const STALE_COMPONENT = [
        ComponentNotFoundException::class,
        LivewireReleaseTokenMismatchException::class,
    ];

    public static function classify(Throwable $exception): ?self
    {
        return match (true) {
            self::isAny($exception, self::TAMPERING) => self::Tampering,
            self::isAny($exception, self::PAYLOAD_LIMIT) => self::PayloadLimit,
            self::isAny($exception, self::STALE_COMPONENT) => self::StaleComponent,
            $exception instanceof TypeError && self::thrownWhileHydratingUpdate($exception) => self::Tampering,
            default => null,
        };
    }

    public function countsAsAttempt(): bool
    {
        return $this === self::Tampering;
    }

    public function status(): int
    {
        return (int) config("livewire-shield.categories.{$this->value}.status", match ($this) {
            self::Tampering => 403,
            self::PayloadLimit => 413,
            self::StaleComponent => 419,
        });
    }

    /**
     * @param  list<class-string>  $classes
     */
    private static function isAny(Throwable $exception, array $classes): bool
    {
        foreach ($classes as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * A bare TypeError is also what a real bug looks like, so it only counts when it was thrown
     * while Livewire was hydrating an incoming property update (e.g. Filament's notifications
     * receiving `[1]` instead of arrays).
     */
    private static function thrownWhileHydratingUpdate(TypeError $exception): bool
    {
        foreach ($exception->getTrace() as $frame) {
            if (($frame['class'] ?? null) === HandleComponents::class
                && $frame['function'] === 'updateProperties') {
                return true;
            }
        }

        return false;
    }
}
