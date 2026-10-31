<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportFileUploads\MissingFileUploadsTraitException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Wireable;
use MonkeySoft\LivewireShield\TamperingDetector;

class StrictWireable implements Wireable
{
    public function __construct(public array $items = []) {}

    public function toLivewire(): array
    {
        return $this->items;
    }

    public static function fromLivewire($value): static
    {
        return new static(array_map(fn (array $item) => $item, $value));
    }
}

class StrictComponent extends Component
{
    public StrictWireable $notifications;

    public function mount(): void
    {
        $this->notifications = new StrictWireable;
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Route::middleware('web')->post('/locked', fn () => throw new CannotUpdateLockedPropertyException('shareUrl'));
    Route::middleware('web')->post('/missing', fn () => throw new PublicPropertyNotFoundException('nope', 'comp'));
    Route::middleware('web')->post('/upload', fn () => throw new MissingFileUploadsTraitException(new StrictComponent));
    Route::middleware('web')->post('/plain-type-error', fn () => throw new TypeError('real bug'));
    Route::middleware('web')->post('/ok', fn () => 'fine');
});

test('a tampered request gets 403 and a payload-free log line', function () {
    // Older dependencies log their own deprecations through the same facade, so only the
    // tampering line is pinned to exactly once; any other warning is let through.
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context = []): bool => $message === 'livewire.tampering'
            && ! in_array('do-not-log', $context, true)
    );
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    $this->withHeaders(['X-Livewire' => '1'])->post('/missing', ['x' => 'do-not-log'])->assertForbidden();
});

// The exception's own render() answers 419 in Livewire 4; the middleware replaces it with 403.
test('a locked property write is a 403, never a 500', function () {
    $this->withHeaders(['X-Livewire' => '1'])->post('/locked')->assertForbidden();
});

test('repeat offenders are throttled', function () {
    foreach (range(1, 5) as $ignored) {
        $this->withHeaders(['X-Livewire' => '1'])->post('/missing')->assertForbidden();
    }

    $this->withHeaders(['X-Livewire' => '1'])->post('/ok')->assertStatus(429)->assertHeader('Retry-After');
});

test('an upload call on a component without the trait is a 403', function () {
    $this->withHeaders(['X-Livewire' => '1'])->post('/upload')->assertForbidden();
});

test('requests without the livewire header are untouched', function () {
    $this->post('/ok')->assertOk();
});

test('a TypeError outside property hydration is not tampering', function () {
    $this->withHeaders(['X-Livewire' => '1'])->post('/plain-type-error')->assertStatus(500);
});

test('detector recognises a TypeError thrown while hydrating a property update', function () {
    $caught = null;

    try {
        Livewire::test(StrictComponent::class)->set('notifications', [1]);
    } catch (TypeError $caught) {
    }

    // Livewire 3 accepts this update without hydrating the Wireable, so there is no TypeError
    // to detect there; the scenario only exists on Livewire 4.
    if ($caught === null) {
        $this->markTestSkipped('This Livewire version does not throw a TypeError for the strict Wireable update.');
    }

    expect($caught)->toBeInstanceOf(TypeError::class)
        ->and(TamperingDetector::isTampering($caught))->toBeTrue();
});
