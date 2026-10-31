<?php

namespace MonkeySoft\LivewireShield;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use MonkeySoft\LivewireShield\Console\DoctorCommand;
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

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'livewire-shield');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/livewire-shield'),
        ], 'livewire-shield-lang');

        Blade::directive('livewireShieldScripts', fn () => '<?php echo \\'.Frontend::class.'::scripts(); ?>');

        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class]);
        }

        $router->pushMiddlewareToGroup(
            (string) config('livewire-shield.middleware_group'),
            BlockTamperedLivewireRequests::class,
        );

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! $handler instanceof Handler) {
                return;
            }

            // The middleware logs and counts guarded requests on the way out. Here the default
            // report is suppressed for classified exceptions (false stops it; null falls through),
            // and requests that never passed the middleware are recorded instead.
            $handler->reportable(function (Throwable $exception) {
                $category = ExceptionCategory::classify($exception);

                if ($category === null) {
                    return null;
                }

                $request = request();

                if ($request->hasHeader('X-Livewire') && ! $request->attributes->get(BlockTamperedLivewireRequests::GUARDED)) {
                    BlockTamperedLivewireRequests::recordAttempt($request, $exception);
                }

                return config("livewire-shield.categories.{$category->value}.report") ? null : false;
            });

            $handler->renderable(function (Throwable $exception, Request $request) {
                $category = ExceptionCategory::classify($exception);

                if ($category === null || ! $request->hasHeader('X-Livewire') || config('app.debug')) {
                    return null;
                }

                return response('', $category->status());
            });
        });
    }
}
