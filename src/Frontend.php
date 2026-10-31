<?php

namespace MonkeySoft\LivewireShield;

use Illuminate\Support\Facades\Vite;

/**
 * Renders the opt-in client script for the `@livewireShieldScripts` directive. Messages are
 * JSON-encoded with HEX flags so translated strings cannot break out of the script tag.
 */
class Frontend
{
    private const STATUSES = [
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        413 => 'payload_limit',
        419 => 'session_expired',
        429 => 'rate_limited',
        500 => 'server_error',
        503 => 'unavailable',
    ];

    public static function scripts(): string
    {
        $config = [
            'messages' => array_map(fn (string $key) => __("livewire-shield::messages.{$key}"), self::STATUSES),
            'reload' => __('livewire-shield::messages.reload'),
            'close' => __('livewire-shield::messages.close'),
            'debug' => (bool) config('app.debug'),
        ];

        $nonce = Vite::cspNonce();
        $nonceAttribute = $nonce ? ' nonce="'.e($nonce).'"' : '';
        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        return "<script{$nonceAttribute}>window.LivewireShieldConfig = {$json};\n"
            .file_get_contents(__DIR__.'/../resources/js/shield.js')
            .'</script>';
    }
}
