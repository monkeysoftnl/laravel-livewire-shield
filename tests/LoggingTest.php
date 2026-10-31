<?php

use Illuminate\Http\Request;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use MonkeySoft\LivewireShield\ExceptionCategory;
use MonkeySoft\LivewireShield\IncidentLogger;

test('a tampering incident is one line without payload values and no exception report', function () {
    $logs = captureLogs();

    livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2, 'name' => 'secret-password-123']]])
        ->assertForbidden();

    $all = $logs->getArrayCopy();
    expect($all)->toHaveCount(1)
        ->and($all[0]->level)->toBe('warning')
        ->and(json_encode($all[0]->context))->not->toContain('secret-password-123')
        ->and($all[0]->context)->toHaveKeys(['ip', 'category', 'incident_id', 'exception', 'status', 'component'])
        ->and($all[0]->context['component'])->toBe('counter');
});

test('the incident id in the log matches the response header', function () {
    $logs = captureLogs();

    $response = livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2]]]);

    expect(shieldLines($logs)[0]->context['incident_id'])->toBe($response->headers->get('X-Shield-Incident'));
});

test('request-controlled identifiers are stripped of control characters and capped', function () {
    $logs = captureLogs();
    $request = Request::create('/livewire/update', 'POST', ['components' => [['snapshot' => json_encode(['memo' => ['name' => "evil\n[CRITICAL] fake".str_repeat('a', 300)]])]]]);

    IncidentLogger::log($request, new CannotUpdateLockedPropertyException("id\r\nx"), ExceptionCategory::Tampering, 403, 'id');

    $context = shieldLines($logs)[0]->context;
    expect($context['component'])->not->toContain("\n")->toHaveLength(100)
        ->and($context['property'])->toBe('idx');
});

test('one address cannot flood the log', function () {
    config(['livewire-shield.logging.max_lines_per_address' => 2, 'livewire-shield.max_attempts' => 100]);
    $logs = captureLogs();

    foreach (range(1, 4) as $ignored) {
        livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2]]])->assertForbidden();
    }

    expect(shieldLines($logs))->toHaveCount(2);
});

test('reaching the limit logs a single lockout line', function () {
    config(['livewire-shield.max_attempts' => 2]);
    $logs = captureLogs();

    foreach (range(1, 2) as $ignored) {
        livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2]]]);
    }
    livewireUpdate([['snapshot' => snapshotOf('counter')]])->assertStatus(429);

    expect(collect(shieldLines($logs))->pluck('message')->all())
        ->toBe(['livewire.tampering', 'livewire.tampering', 'livewire.lockout']);
});

test('logging can be switched off and the address left out', function () {
    $logs = captureLogs();

    config(['livewire-shield.logging.include_ip' => false]);
    livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2]]]);
    expect(shieldLines($logs)[0]->context)->not->toHaveKey('ip');

    config(['livewire-shield.logging.enabled' => false]);
    livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => ['ownerId' => 2]]]);
    expect(shieldLines($logs))->toHaveCount(1);
});

test('report true hands classified exceptions to the reporter as well', function () {
    config(['livewire-shield.categories.tampering.report' => true]);
    $logs = captureLogs();

    livewireUpdate([['snapshot' => snapshotOf('counter'), 'calls' => [call('nope')]], ['snapshot' => snapshotOf('counter')]]);

    // MethodNotFoundException reports itself in Livewire 4, so only Livewire 3 adds an error line.
    expect(collect($logs)->pluck('message'))->toContain('livewire.tampering');
});
