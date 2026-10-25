<?php

namespace MonkeySoft\LivewireShield;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use MonkeySoft\LivewireShield\Http\Middleware\BlockTamperedLivewireRequests;
use Throwable;

class LivewireShieldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/livewire-shield.php', 'livewire-shield');
    }

    public function boot(Router $router): void
    {
        $this->publishes([
            __DIR__.'/../config/livewire-shield.php' => config_path('livewire-shield.php'),
        ], 'livewire-shield-config');

        $router->pushMiddlewareToGroup(
            (string) config('livewire-shield.middleware_group'),
            BlockTamperedLivewireRequests::class,
        );

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! $handler instanceof Handler) {
                return;
            }

            // Returning false stops the default report; anything else falls through, so
            // exceptions that are not tampering are reported exactly as before.
            $handler->reportable(function (Throwable $exception) {
                if (! TamperingDetector::isTampering($exception)) {
                    return null;
                }

                BlockTamperedLivewireRequests::recordAttempt(request(), $exception);

                return false;
            });

            $handler->renderable(function (Throwable $exception, Request $request) {
                if ($request->hasHeader('X-Livewire') && TamperingDetector::isTampering($exception)) {
                    return response('', 403);
                }

                return null;
            });
        });
    }
}
