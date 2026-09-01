<?php

namespace Aura\Redirects\Services;

use Aura\Redirects\Contracts\ResolvesRedirectContext;
use Aura\Redirects\Data\RedirectDefinition;
use Aura\Redirects\Support\QueryStringMerger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RedirectMatcher
{
    public function __construct(
        private readonly RedirectCacheStore $cacheStore,
        private readonly RedirectHitRecorder $hitRecorder,
        private readonly RedirectPathNormalizer $pathNormalizer,
        private readonly ProtectedPathMatcher $protectedPathMatcher,
        private readonly QueryStringMerger $queryStringMerger,
        private readonly RedirectRepository $repository,
        private readonly ResolvesRedirectContext $resolver,
    ) {}

    public function match(Request $request): ?RedirectDefinition
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        $path = $this->pathNormalizer->normalizeRequestPath($request);

        if ($path === null || $this->protectedPathMatcher->matches($path)) {
            return null;
        }

        $context = $this->resolver->resolve($request);

        if ($context === null) {
            return null;
        }

        return $this->cacheStore->rememberMatch(
            $context,
            $path,
            fn (): ?RedirectDefinition => $this->repository->findActiveMatch($context, $path),
        );
    }

    public function toResponse(Request $request): ?RedirectResponse
    {
        $definition = $this->match($request);

        if ($definition === null) {
            return null;
        }

        $location = $definition->preserveQuery
            ? $this->queryStringMerger->merge($definition->location, $this->rawQueryString($request))
            : $definition->location;

        $response = $definition->internal
            ? redirect($location, $definition->status)
            : redirect()->away($location, $definition->status);

        if ($request->isMethod('HEAD')) {
            $response->setContent('');
        }

        $this->hitRecorder->record($definition);

        return $response;
    }

    private function rawQueryString(Request $request): ?string
    {
        $requestUri = (string) ($request->server('REQUEST_URI') ?? $request->getRequestUri());
        $query = parse_url($requestUri, PHP_URL_QUERY);

        return is_string($query) ? $query : null;
    }
}
