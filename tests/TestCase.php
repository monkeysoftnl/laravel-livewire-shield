<?php

namespace MonkeySoft\LivewireShield\Tests;

use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use MonkeySoft\LivewireShield\LivewireShieldServiceProvider;
use MonkeySoft\LivewireShield\Tests\Fixtures\Counter;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, LivewireShieldServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.debug', false);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Livewire::component('counter', Counter::class);
    }
}
