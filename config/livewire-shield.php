<?php

return [
    /*
     * Tampering attempts allowed per address within `decay_seconds` before further
     * Livewire requests from that address are refused with a 429.
     */
    'max_attempts' => 5,

    'decay_seconds' => 900,

    /*
     * Middleware group the throttle is appended to. The detection and the 403 work
     * regardless; this only controls where the pre-check runs.
     */
    'middleware_group' => 'web',

    /*
     * Log channel for the `livewire.tampering` warning. Null uses the default channel.
     */
    'log_channel' => null,
];
