<?php

namespace MonkeySoft\LivewireShield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use MonkeySoft\LivewireShield\Contracts\TenantResolver;
use MonkeySoft\LivewireShield\ExceptionCategory;
use MonkeySoft\LivewireShield\Exceptions\PayloadLimitExceeded;
use MonkeySoft\LivewireShield\IncidentLogger;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Guards Livewire requests: refuses an address that keeps sending hand-edited payloads, applies
 * the optional update rate limit and payload guard, and turns the Livewire failures Shield
 * classifies into a bare status with an incident id.
 *
 * Detection happens on the way out, by reading the exception the routing pipeline attaches to
 * the response. That works regardless of whether the exception has its own `report()` (which
 * in production stops Laravel before any `reportable` callback) or `render()` method.
 */
class BlockTamperedLivewireRequests
{
    public const GUARDED = 'livewire-shield.guarded';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        $key = self::attemptKey($request);

        if (RateLimiter::tooManyAttempts($key, (int) config('livewire-shield.max_attempts'))) {
            return self::tooManyRequests(RateLimiter::availableIn($key));
        }

        if ($throttled = $this->throttleUpdates($request)) {
            return $throttled;
        }

        if ($limit = $this->exceededPayloadLimit($request)) {
            $exception = new PayloadLimitExceeded($limit);

            return self::handleIncident($request, $exception, ExceptionCategory::PayloadLimit, null);
        }

        $request->attributes->set(self::GUARDED, true);

        $response = $next($request);

        $exception = $response->exception ?? null;
        $category = $exception instanceof Throwable ? ExceptionCategory::classify($exception) : null;

        return $category ? self::handleIncident($request, $exception, $category, $response) : $response;
    }

    /**
     * Counts and logs an incident outside the middleware, for requests that never passed it.
     */
    public static function recordAttempt(Request $request, Throwable $exception): void
    {
        $category = ExceptionCategory::classify($exception) ?? ExceptionCategory::Tampering;

        IncidentLogger::log($request, $exception, $category, $category->status(), (string) Str::ulid());
        self::count($request, $category);
    }

    private static function handleIncident(Request $request, Throwable $exception, ExceptionCategory $category, ?Response $response): Response
    {
        $incidentId = (string) Str::ulid();
        $status = $category->status();

        IncidentLogger::log($request, $exception, $category, $status, $incidentId);
        self::count($request, $category);

        // In debug the original response (usually Laravel's error page) stays, so developers
        // still see what went wrong; logging and counting happen either way.
        if ($response !== null && config('app.debug')) {
            $response->headers->set('X-Shield-Incident', $incidentId);

            return $response;
        }

        return response('', $status, ['X-Shield-Incident' => $incidentId]);
    }

    private static function count(Request $request, ExceptionCategory $category): void
    {
        if (! $category->countsAsAttempt()) {
            return;
        }

        $key = self::attemptKey($request);
        $max = (int) config('livewire-shield.max_attempts');

        RateLimiter::hit($key, (int) config('livewire-shield.decay_seconds'));

        if (RateLimiter::attempts($key) === $max) {
            IncidentLogger::lockout($request);
        }
    }

    private function throttleUpdates(Request $request): ?Response
    {
        if (! config('livewire-shield.rate_limit.enabled')) {
            return null;
        }

        $user = $request->user();
        $max = (int) config($user ? 'livewire-shield.rate_limit.per_minute_user' : 'livewire-shield.rate_limit.per_minute_guest');
        $key = 'livewire-shield-rate:'.sha1(implode('|', [
            $this->tenant($request),
            $user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip(),
        ]));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return self::tooManyRequests(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function tenant(Request $request): string
    {
        $resolver = config('livewire-shield.rate_limit.tenant_resolver');

        if (! is_string($resolver) || $resolver === '') {
            return '';
        }

        $tenant = app($resolver);

        return $tenant instanceof TenantResolver ? (string) $tenant->resolve($request) : '';
    }

    /**
     * Livewire 4 enforces its own `livewire.payload` limits; this guard covers versions that have
     * none (or every version when `payload.enabled` is true). It only counts what the update
     * endpoint already decodes, and leaves malformed structures to Livewire's own 404.
     */
    private function exceededPayloadLimit(Request $request): ?string
    {
        $enabled = config('livewire-shield.payload.enabled', 'auto');

        if ($enabled === false || ($enabled === 'auto' && config('livewire.payload') !== null)) {
            return null;
        }

        $limits = (array) config('livewire-shield.payload');

        if (isset($limits['max_bytes']) && strlen((string) $request->getContent()) > (int) $limits['max_bytes']) {
            return 'max_bytes';
        }

        $components = $request->input('components');

        if (! is_array($components)) {
            return null;
        }

        if (isset($limits['max_components']) && count($components) > (int) $limits['max_components']) {
            return 'max_components';
        }

        $calls = $updates = 0;

        foreach ($components as $component) {
            $calls += is_array($component['calls'] ?? null) ? count($component['calls']) : 0;
            $updates += is_array($component['updates'] ?? null) ? count($component['updates']) : 0;
        }

        return match (true) {
            isset($limits['max_calls']) && $calls > (int) $limits['max_calls'] => 'max_calls',
            isset($limits['max_updates']) && $updates > (int) $limits['max_updates'] => 'max_updates',
            default => null,
        };
    }

    private static function tooManyRequests(int $retryAfter): Response
    {
        return response('', 429, ['Retry-After' => $retryAfter]);
    }

    private static function attemptKey(Request $request): string
    {
        return 'livewire-tamper:'.$request->ip();
    }
}
