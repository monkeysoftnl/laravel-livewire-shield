<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

test('the script directive prints escaped messages, the debug flag and the CSP nonce', function () {
    __('livewire-shield::messages.reload');
    app('translator')->addLines(['messages.forbidden' => '</script><img src=x onerror=alert(1)>'], 'en', 'livewire-shield');
    Vite::useCspNonce('abc123');

    $html = Blade::render('@livewireShieldScripts');

    expect($html)->toStartWith('<script nonce="abc123">')
        ->and(substr_count($html, '</script>'))->toBe(1)
        ->and($html)->not->toContain('</script><img')
        ->and($html)->toContain('"debug":false')
        ->and($html)->toContain('window.__livewireShield');
});

test('messages exist in English and Dutch', function () {
    app()->setLocale('nl');
    expect(__('livewire-shield::messages.session_expired'))->toContain('verlopen');

    app()->setLocale('en');
    expect(__('livewire-shield::messages.session_expired'))->toContain('expired');
});

test('doctor flags a cache that cannot hold rate limits and never claims security', function () {
    $this->artisan('livewire-shield:doctor')
        ->expectsOutputToContain('does not persist between requests')
        ->expectsOutputToContain('does not prove the installation is secure')
        ->assertFailed();
});

test('doctor passes on a sound configuration', function () {
    config([
        'cache.default' => 'database',
        'cache.stores.database.driver' => 'database',
        'livewire.temporary_file_upload.rules' => ['file', 'mimes:png', 'max:1024'],
    ]);

    $this->artisan('livewire-shield:doctor')->expectsOutputToContain('Shield middleware is in the [web] group')->assertSuccessful();
});
