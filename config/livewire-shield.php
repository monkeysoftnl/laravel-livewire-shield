<?php

return [
    /*
     * Tampering attempts allowed per address within `decay_seconds` before further
     * Livewire requests from that address are refused with a 429.
     */
    'max_attempts' => 5,

    'decay_seconds' => 900,

    /*
     * Middleware group the guard is appended to. Livewire's update route uses `web` unless
     * you registered a custom one; the exception callbacks work regardless.
     */
    'middleware_group' => 'web',

    /*
     * Log channel for the `livewire.*` warnings. Null uses the default channel.
     */
    'log_channel' => null,

    /*
     * Response status per category, used outside debug mode. `report` true also passes the
     * exception to your exception reporter (Sentry, Flare, ...) as an ordinary error.
     */
    'categories' => [
        'tampering' => ['status' => 403, 'report' => false],
        'payload_limit' => ['status' => 413, 'report' => false],
        'stale_component' => ['status' => 419, 'report' => false],
    ],

    /*
     * Payload guard. 'auto' only enforces it when Livewire has no `livewire.payload` limits of
     * its own (Livewire 3); true always, false never. Set a limit to null to disable it. Your
     * web server and PHP (`post_max_size`) still need their own body-size limits.
     */
    'payload' => [
        'enabled' => 'auto',
        'max_bytes' => 1024 * 1024,
        'max_components' => 200,
        'max_calls' => 50,
        'max_updates' => 500,
    ],

    /*
     * Optional limit on all Livewire update traffic, per user (authenticated) or per address
     * (guests). Off by default: polling and wire:model.live send many legitimate requests.
     * `tenant_resolver` is a class implementing MonkeySoft\LivewireShield\Contracts\TenantResolver.
     */
    'rate_limit' => [
        'enabled' => false,
        'per_minute_guest' => 120,
        'per_minute_user' => 300,
        'tenant_resolver' => null,
    ],

    'logging' => [
        'enabled' => true,
        'include_ip' => true,
        // Lines one address can produce per `decay_seconds`; further incidents still count.
        'max_lines_per_address' => 20,
    ],
];
