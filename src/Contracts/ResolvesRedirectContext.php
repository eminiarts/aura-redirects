<?php

namespace Aura\Redirects\Contracts;

use Aura\Redirects\Data\ResolvedRedirectContext;
use Illuminate\Http\Request;

interface ResolvesRedirectContext
{
    public function resolve(Request $request): ?ResolvedRedirectContext;
}
