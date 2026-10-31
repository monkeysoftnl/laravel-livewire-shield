<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException;
use MonkeySoft\LivewireShield\ExceptionCategory;
use MonkeySoft\LivewireShield\Exceptions\PayloadLimitExceeded;
use MonkeySoft\LivewireShield\TamperingDetector;

test('livewire tampering exceptions classify as tampering', function (Throwable $exception) {
    expect(ExceptionCategory::classify($exception))->toBe(ExceptionCategory::Tampering)
        ->and(TamperingDetector::isTampering($exception))->toBeTrue();
})->with([
    'locked' => fn () => new CannotUpdateLockedPropertyException('id'),
    'corrupt' => fn () => new CorruptComponentPayloadException,
    'unknown property' => fn () => new PublicPropertyNotFoundException('x', 'c'),
    'unknown method' => fn () => new MethodNotFoundException('x'),
]);

test('payload guards and stale components have their own category', function () {
    expect(ExceptionCategory::classify(new PayloadLimitExceeded('max_calls')))->toBe(ExceptionCategory::PayloadLimit)
        ->and(ExceptionCategory::classify(new ComponentNotFoundException))->toBe(ExceptionCategory::StaleComponent);
});

test('framework and application exceptions are left alone', function (Throwable $exception) {
    expect(ExceptionCategory::classify($exception))->toBeNull();
})->with([
    'validation' => fn () => ValidationException::withMessages(['a' => 'b']),
    'unauthenticated' => fn () => new AuthenticationException,
    'unauthorized' => fn () => new AuthorizationException,
    'csrf' => fn () => new TokenMismatchException,
    'missing model' => fn () => new ModelNotFoundException,
    'throttled' => fn () => new ThrottleRequestsException,
    'plain bug' => fn () => new RuntimeException('bug'),
    'type error elsewhere' => fn () => new TypeError('bug'),
]);

test('statuses come from config', function () {
    config(['livewire-shield.categories.tampering.status' => 404]);

    expect(ExceptionCategory::Tampering->status())->toBe(404)
        ->and(ExceptionCategory::PayloadLimit->status())->toBe(413)
        ->and(ExceptionCategory::StaleComponent->status())->toBe(419);
});
