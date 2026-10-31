<?php

namespace MonkeySoft\LivewireShield\Exceptions;

use RuntimeException;

/**
 * Thrown by Shield's own payload guard on Livewire versions that have none. The message names
 * the limit only, never anything from the payload.
 */
class PayloadLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $limit)
    {
        parent::__construct("Livewire request exceeds the [{$limit}] limit.");
    }
}
