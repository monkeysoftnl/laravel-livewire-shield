<?php

namespace MonkeySoft\LivewireShield\Tests\Fixtures;

use Livewire\Attributes\Locked;
use Livewire\Component;

class Counter extends Component
{
    public int $count = 0;

    #[Locked]
    public int $ownerId = 1;

    public string $name = '';

    /** @var array<string, string> */
    public array $fields = [];

    public function increment(): void
    {
        $this->count++;
    }

    public function save(): void
    {
        $this->validate(['name' => 'required|min:3']);
    }

    public function render(): string
    {
        return '<div>{{ $count }}</div>';
    }
}
