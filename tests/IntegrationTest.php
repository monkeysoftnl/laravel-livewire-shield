<?php

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
 * Real requests to Livewire's update endpoint, so the whole stack (routing pipeline, Livewire's
 * own exceptions with their report()/render() methods, the exception handler) is exercised.
 */

test('a normal update passes through untouched', function () {
    $response = livewireUpdate([['snapshot' => snapshotOf('counter'), 'calls' => [call('increment')]]]);

    $response->assertOk()->assertHeaderMissing('X-Shield-Incident');
    expect(json_decode($response->json('components.0.snapshot'), true)['data']['count'])->toBe(1);
});

test('bundled components in one request pass through', function () {
    livewireUpdate([
        ['snapshot' => snapshotOf('counter'), 'calls' => [call('increment')]],
        ['snapshot' => snapshotOf('counter'), 'updates' => ['name' => 'Ada']],
    ])->assertOk()->assertJsonCount(2, 'components');
});

test('a validation error stays a normal Livewire response', function () {
    $response = livewireUpdate([['snapshot' => snapshotOf('counter'), 'calls' => [call('save')]]]);

    $response->assertOk();
    expect(json_decode($response->json('components.0.snapshot'), true)['memo']['errors'])->toHaveKey('name');
});

test('a modified snapshot is refused with 403 and counted', function () {
    $snapshot = json_decode(snapshotOf('counter'), true);
    $snapshot['data']['count'] = 1000;

    livewireUpdate([['snapshot' => json_encode($snapshot)]])
        ->assertForbidden()
        ->assertHeader('X-Shield-Incident');

    expect(RateLimiter::attempts('livewire-tamper:127.0.0.1'))->toBe(1);
});

// Regression: in production the exception's own report() returns true, which stops Laravel
// before any reportable callback, so 1.x never counted or logged these.
test('a locked property write is counted and logged in production', function () {
    $logs = captureLogs();

    livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2]]])->assertForbidden();

    $lines = shieldLines($logs);
    expect(RateLimiter::attempts('livewire-tamper:127.0.0.1'))->toBe(1)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->message)->toBe('livewire.tampering')
        ->and($lines[0]->context['property'])->toBe('ownerId');
});

test('calling a method that does not exist is tampering', function () {
    livewireUpdate([['snapshot' => snapshotOf('counter'), 'calls' => [call('deleteEverything')]]])->assertForbidden();
});

test('an upload call on a component without WithFileUploads is tampering', function () {
    livewireUpdate([['snapshot' => snapshotOf('counter'), 'calls' => [call('_startUpload', ['photo', [['name' => 'x.png', 'size' => 1, 'type' => 'image/png']], false])]]])
        ->assertForbidden();
});

test('repeat offenders get 429 with Retry-After before the payload is handled', function () {
    foreach (range(1, 5) as $ignored) {
        livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2]]])->assertForbidden();
    }

    livewireUpdate([['snapshot' => snapshotOf('counter'), 'calls' => [call('increment')]]])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

test('debug mode keeps the original response for developers but still counts', function () {
    config(['app.debug' => true]);

    $response = livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2]]]);

    expect($response->status())->not->toBe(403)
        ->and($response->headers->has('X-Shield-Incident'))->toBeTrue()
        ->and(RateLimiter::attempts('livewire-tamper:127.0.0.1'))->toBe(1);
});

test('production responses never contain exception details', function () {
    $response = livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2]]]);

    expect($response->getContent())->toBe('');
});

test('non-Livewire routes are untouched, including their errors', function () {
    Route::middleware('web')->post('/plain', fn () => throw new RuntimeException('real bug'));
    Route::middleware('web')->post('/fine', fn () => 'fine');
    $logs = captureLogs();

    $this->post('/fine')->assertOk();
    $this->post('/plain')->assertStatus(500);

    expect(shieldLines($logs))->toBe([])
        ->and(collect($logs)->pluck('level'))->toContain('error');
});
