<?php

namespace MonkeySoft\LivewireShield\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Shuts out an address that keeps sending hand-edited Livewire payloads.
 *
 * Livewire already refuses the write itself, so nothing is exposed. The exception is caught
 * per layer by the router's pipeline before it could reach a middleware, which is why the
 * service provider reports it through `recordAttempt()` and answers 403; this class only
 * refuses an address that has used up its attempts, before the payload is handled at all.
 *
 * The payload is deliberately never logged: the tampered property may sit next to secrets.
 */
class BlockTamperedLivewireRequests
{
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
            return response('', 429, ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        return $next($request);
    }

    public static function recordAttempt(Request $request, Throwable $exception): void
    {
        RateLimiter::hit(self::attemptKey($request), (int) config('livewire-shield.decay_seconds'));

        Log::channel(config('livewire-shield.log_channel'))->warning('livewire.tampering', [
            'ip' => $request->ip(),
            'user_id' => $request->user()?->getKey(),
            'path' => $request->path(),
            'exception' => $exception::class,
            'property' => $exception instanceof CannotUpdateLockedPropertyException ? $exception->property : null,
        ]);
    }

    private static function attemptKey(Request $request): string
    {
        return 'livewire-tamper:'.$request->ip();
    }
}
