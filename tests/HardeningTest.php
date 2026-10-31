<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use MonkeySoft\LivewireShield\Contracts\TenantResolver;
use MonkeySoft\LivewireShield\Tests\Fixtures\Counter;

class HeaderTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?string
    {
        return $request->header('X-Tenant');
    }
}

// Shield's own payload guard only runs where Livewire has none (Livewire 3), or when forced.
describe('payload guard', function () {
    beforeEach(fn () => config(['livewire-shield.payload.enabled' => true]));

    test('too many calls is a 413 and not counted as tampering', function () {
        config(['livewire-shield.payload.max_calls' => 2]);

        livewireUpdate([['snapshot' => snapshotOf('counter'), 'calls' => array_fill(0, 3, call('increment'))]])
            ->assertStatus(413);

        expect(RateLimiter::attempts('livewire-tamper:127.0.0.1'))->toBe(0);
    });

    test('too many bundled components is a 413', function () {
        config(['livewire-shield.payload.max_components' => 1]);

        livewireUpdate([['snapshot' => snapshotOf('counter')], ['snapshot' => snapshotOf('counter')]])->assertStatus(413);
    });

    test('an oversized body is a 413', function () {
        config(['livewire-shield.payload.max_bytes' => 100]);

        livewireUpdate([['snapshot' => snapshotOf('counter')]])->assertStatus(413);
    });

    test('a large legitimate form stays under the defaults', function () {
        $updates = [];
        foreach (range(1, 400) as $i) {
            $updates["fields.field{$i}"] = str_repeat('x', 200);
        }

        livewireUpdate([['snapshot' => snapshotOf('counter'), 'updates' => $updates]])->assertOk();
    });

    test('auto mode defers to Livewire when it has its own limits', function () {
        config(['livewire-shield.payload.enabled' => 'auto', 'livewire-shield.payload.max_calls' => 1, 'livewire.payload' => ['max_calls' => 50]]);

        livewireUpdate([['snapshot' => snapshotOf('counter'), 'calls' => [call('increment'), call('increment')]]])->assertOk();
    });

    test('auto mode enforces when Livewire has no limits', function () {
        config(['livewire-shield.payload.enabled' => 'auto', 'livewire-shield.payload.max_calls' => 1, 'livewire.payload' => null]);

        livewireUpdate([['snapshot' => snapshotOf('counter'), 'calls' => [call('increment'), call('increment')]]])->assertStatus(413);
    });
});

describe('update rate limit', function () {
    beforeEach(fn () => config([
        'livewire-shield.rate_limit.enabled' => true,
        'livewire-shield.rate_limit.per_minute_guest' => 2,
        'livewire-shield.rate_limit.per_minute_user' => 3,
    ]));

    test('is off by default', function () {
        config(['livewire-shield.rate_limit.enabled' => false, 'livewire-shield.rate_limit.per_minute_guest' => 0]);

        livewireUpdate([['snapshot' => snapshotOf('counter')]])->assertOk();
    });

    test('limits guests per address with Retry-After', function () {
        livewireUpdate([['snapshot' => snapshotOf('counter')]])->assertOk();
        livewireUpdate([['snapshot' => snapshotOf('counter')]])->assertOk();
        livewireUpdate([['snapshot' => snapshotOf('counter')]])->assertStatus(429)->assertHeader('Retry-After');
    });

    test('limits users per account, separate from guests on the same address', function () {
        $user = new User;
        $user->forceFill(['id' => 7]);

        livewireUpdate([['snapshot' => snapshotOf('counter')]]);
        livewireUpdate([['snapshot' => snapshotOf('counter')]]);

        $this->actingAs($user);
        foreach (range(1, 3) as $ignored) {
            livewireUpdate([['snapshot' => snapshotOf('counter')]])->assertOk();
        }
        livewireUpdate([['snapshot' => snapshotOf('counter')]])->assertStatus(429);
    });

    test('scopes the limit per tenant through the resolver', function () {
        config(['livewire-shield.rate_limit.tenant_resolver' => HeaderTenantResolver::class]);

        livewireUpdate([['snapshot' => snapshotOf('counter')]], ['X-Tenant' => 'a']);
        livewireUpdate([['snapshot' => snapshotOf('counter')]], ['X-Tenant' => 'a']);
        livewireUpdate([['snapshot' => snapshotOf('counter')]], ['X-Tenant' => 'a'])->assertStatus(429);
        livewireUpdate([['snapshot' => snapshotOf('counter')]], ['X-Tenant' => 'b'])->assertOk();
    });
});

test('the test component class exists', fn () => expect(class_exists(Counter::class))->toBeTrue());
