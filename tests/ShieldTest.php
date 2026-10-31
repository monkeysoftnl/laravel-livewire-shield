<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Component;
use Livewire\Exceptions\PublicPropertyNotFoundException;
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
    $this->withoutMiddleware(Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    Route::middleware('web')->post('/locked', fn () => throw new CannotUpdateLockedPropertyException('shareUrl'));
    Route::middleware('web')->post('/missing', fn () => throw new PublicPropertyNotFoundException('nope', 'comp'));
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

// Livewire 4's exception carries a render() that Laravel prefers over any callback, so there
// it keeps answering with its bare 419; Livewire 3 gets our 403. Both are reported and counted.
test('a locked property write is counted and never a 500', function () {
    $status = $this->withHeaders(['X-Livewire' => '1'])->post('/locked')->getStatusCode();

    expect($status)->toBeIn([403, 419]);
});

test('repeat offenders are throttled', function () {
    foreach (range(1, 5) as $ignored) {
        $this->withHeaders(['X-Livewire' => '1'])->post('/missing')->assertForbidden();
    }

    $this->withHeaders(['X-Livewire' => '1'])->post('/ok')->assertStatus(429)->assertHeader('Retry-After');
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
