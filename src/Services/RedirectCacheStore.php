<?php

namespace Aura\Redirects\Services;

use Aura\Redirects\Data\RedirectDefinition;
use Aura\Redirects\Data\ResolvedRedirectContext;
use Aura\Redirects\Models\Redirect;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

class RedirectCacheStore
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly RedirectRepository $repository,
    ) {}

    /**
     * @param  callable(): ?RedirectDefinition  $resolver
     */
    public function rememberMatch(ResolvedRedirectContext $context, string $normalizedSource, callable $resolver): ?RedirectDefinition
    {
        $repository = $this->store();
        $key = $this->matchCacheKey($context, $normalizedSource);
        $payload = $repository->remember($key, (int) config('aura-redirects.cache.ttl_seconds', 300), function () use ($resolver): array {
            $definition = $resolver();

            return $definition?->toCachePayload() ?? ['__null' => true];
        });

        if (($payload['__null'] ?? false) === true) {
            return null;
        }

        return RedirectDefinition::fromCachePayload($payload);
    }

    /**
     * @return array<int, string>
     */
    public function invalidateScopeHashes(array $scopeHashes): array
    {
        $repository = $this->store();

        foreach (array_values(array_unique(array_filter($scopeHashes))) as $scopeHash) {
            $key = $this->versionCacheKey($scopeHash);
            $repository->forever($key, ((int) $repository->get($key, 1)) + 1);
        }

        return $scopeHashes;
    }

    public function warm(): int
    {
        $warmed = 0;

        foreach (Redirect::query()->withoutGlobalScopes()->where('enabled', true)->get() as $redirect) {
            if (! is_string($redirect->site_key) || $redirect->site_key === '' || ! is_string($redirect->host) || $redirect->host === '') {
                continue;
            }

            $context = new ResolvedRedirectContext(
                siteKey: (string) $redirect->site_key,
                host: (string) $redirect->host,
                teamId: $redirect->team_id === null ? null : (int) $redirect->team_id,
                timezone: $this->timezoneForSite((string) $redirect->site_key),
            );

            $this->rememberMatch(
                $context,
                (string) $redirect->normalized_source,
                fn (): ?RedirectDefinition => $this->repository->findActiveMatch($context, (string) $redirect->normalized_source),
            );

            $warmed++;
        }

        return $warmed;
    }

    private function matchCacheKey(ResolvedRedirectContext $context, string $normalizedSource): string
    {
        return implode(':', [
            (string) config('aura-redirects.cache.prefix', 'aura-redirects'),
            'match',
            $context->scopeHash(),
            'v'.$this->scopeVersion($context->scopeHash()),
            hash('sha256', $normalizedSource),
        ]);
    }

    private function scopeVersion(string $scopeHash): int
    {
        return (int) $this->store()->get($this->versionCacheKey($scopeHash), 1);
    }

    private function store(): Repository
    {
        $store = config('aura-redirects.cache.store');

        return is_string($store) && $store !== ''
            ? $this->cache->store($store)
            : $this->cache->store();
    }

    private function timezoneForSite(string $siteKey): string
    {
        $sites = (array) config('aura-redirects.resolver.sites', []);

        return (string) ($sites[$siteKey]['timezone'] ?? config('app.timezone', 'UTC'));
    }

    private function versionCacheKey(string $scopeHash): string
    {
        return implode(':', [
            (string) config('aura-redirects.cache.prefix', 'aura-redirects'),
            'version',
            $scopeHash,
        ]);
    }
}
