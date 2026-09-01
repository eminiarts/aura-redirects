<?php

namespace Aura\Redirects\Middleware;

use Aura\Redirects\Services\RedirectMatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleAuraRedirects
{
    public function __construct(
        private readonly RedirectMatcher $matcher,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $this->matcher->toResponse($request);

        if ($response !== null) {
            return $response;
        }

        return $next($request);
    }
}
