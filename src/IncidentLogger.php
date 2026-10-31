<?php

namespace MonkeySoft\LivewireShield;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Throwable;

/**
 * Writes one structured line per incident.
 *
 * The payload, the exception message, cookies and headers are deliberately never logged: the
 * tampered property may sit next to secrets, and messages can quote user input. The only values
 * that come from the request are the component name and the locked property name, both reduced
 * to a short identifier so they cannot inject lines or flood the log.
 */
class IncidentLogger
{
    private const IDENTIFIER_LENGTH = 100;

    public static function log(Request $request, Throwable $exception, ExceptionCategory $category, int $status, string $incidentId): void
    {
        if (! config('livewire-shield.logging.enabled', true) || ! self::withinBudget($request)) {
            return;
        }

        $context = [
            'category' => $category->value,
            'incident_id' => $incidentId,
            'exception' => $exception::class,
            'status' => $status,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'path' => self::identifier($request->path()),
            'component' => self::componentName($request),
            'property' => $exception instanceof CannotUpdateLockedPropertyException ? self::identifier((string) $exception->property) : null,
        ];

        if (config('livewire-shield.logging.include_ip', true)) {
            $context = ['ip' => $request->ip()] + $context;
        }

        Log::channel(config('livewire-shield.log_channel'))->warning("livewire.{$category->value}", $context);
    }

    public static function lockout(Request $request): void
    {
        if (! config('livewire-shield.logging.enabled', true)) {
            return;
        }

        Log::channel(config('livewire-shield.log_channel'))->warning('livewire.lockout', [
            'ip' => config('livewire-shield.logging.include_ip', true) ? $request->ip() : null,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'decay_seconds' => (int) config('livewire-shield.decay_seconds'),
        ]);
    }

    /**
     * Caps the lines one address can produce per window, so a script cannot fill the disk.
     */
    private static function withinBudget(Request $request): bool
    {
        $key = 'livewire-shield-log:'.sha1((string) $request->ip());
        $max = (int) config('livewire-shield.logging.max_lines_per_address', 20);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }

        RateLimiter::hit($key, (int) config('livewire-shield.decay_seconds'));

        return true;
    }

    private static function componentName(Request $request): ?string
    {
        $snapshot = $request->input('components.0.snapshot');

        if (! is_string($snapshot)) {
            return null;
        }

        $name = json_decode($snapshot, true)['memo']['name'] ?? null;

        return is_string($name) ? self::identifier($name) : null;
    }

    private static function identifier(string $value): string
    {
        return mb_substr((string) preg_replace('/[^A-Za-z0-9_.:\-\/]/', '', $value), 0, self::IDENTIFIER_LENGTH);
    }
}
