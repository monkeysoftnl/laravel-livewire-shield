<?php

namespace MonkeySoft\LivewireShield\Contracts;

use Illuminate\Http\Request;

/**
 * Optional: scopes the update rate limit per tenant. Configure the class name under
 * `livewire-shield.rate_limit.tenant_resolver`; it is resolved from the container per request.
 */
interface TenantResolver
{
    public function resolve(Request $request): ?string;
}
