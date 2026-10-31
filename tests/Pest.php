<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use MonkeySoft\LivewireShield\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Renders a component the way a browser receives it and returns its snapshot string.
 */
function snapshotOf(string $component): string
{
    preg_match('/wire:snapshot="([^"]+)"/', Blade::render("@livewire('{$component}')"), $matches);

    return html_entity_decode($matches[1], ENT_QUOTES);
}

/**
 * Posts a real request to Livewire's update endpoint.
 *
 * @param  list<array{snapshot: string, updates?: array<string, mixed>, calls?: list<array<string, mixed>>}>  $components
 */
function livewireUpdate(array $components, array $headers = []): TestResponse
{
    $payload = array_map(fn (array $component) => [
        'snapshot' => $component['snapshot'],
        'updates' => $component['updates'] ?? [],
        'calls' => $component['calls'] ?? [],
    ], $components);

    return test()->withHeaders(['X-Livewire' => '1'] + $headers)
        ->postJson(Livewire::getUpdateUri(), ['components' => $payload]);
}

function call(string $method, array $params = []): array
{
    return ['path' => '', 'method' => $method, 'params' => $params];
}

/**
 * Collects every log line written during the test, regardless of channel.
 *
 * @return ArrayObject<int, MessageLogged>
 */
function captureLogs(): ArrayObject
{
    $logs = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $logs->append($event));

    return $logs;
}

/**
 * @param  ArrayObject<int, MessageLogged>  $logs
 * @return list<MessageLogged>
 */
function shieldLines(ArrayObject $logs): array
{
    return array_values(array_filter($logs->getArrayCopy(), fn (MessageLogged $e) => str_starts_with($e->message, 'livewire.')));
}
