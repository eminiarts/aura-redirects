<?php

namespace Aura\Redirects\Services;

use Aura\Redirects\Data\NormalizedDestination;
use Aura\Redirects\Support\RedirectScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class RedirectDestinationNormalizer
{
    public function __construct(
        private readonly RedirectPathNormalizer $pathNormalizer,
    ) {}

    public function normalize(string $destination, ?string $scopeHost, ?string $siteKey): NormalizedDestination
    {
        $destination = trim($destination);

        if ($destination === '') {
            throw new InvalidArgumentException('Destination is required.');
        }

        if (preg_match("/[\r\n]/", $destination) === 1) {
            throw new InvalidArgumentException('Destination cannot contain line breaks.');
        }

        if (str_starts_with($destination, '//')) {
            throw new InvalidArgumentException('Protocol-relative destinations are not allowed.');
        }

        if (str_starts_with($destination, '/')) {
            return $this->normalizeInternalPath($destination, $scopeHost);
        }

        $parts = parse_url($destination);

        if ($parts === false) {
            throw new InvalidArgumentException('Destination must be an internal absolute path or absolute URL.');
        }

        if (isset($parts['scheme']) && ! isset($parts['host'])) {
            $scheme = strtolower((string) $parts['scheme']);

            if (! in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Destination scheme must be http or https.');
            }

            throw new InvalidArgumentException('Destination host is required.');
        }

        if (! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Destination must be an internal absolute path or absolute URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Destination scheme must be http or https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Destination cannot embed credentials.');
        }

        $host = RedirectScope::normalizeHost((string) $parts['host']);

        if ($host === '') {
            throw new InvalidArgumentException('Destination host is required.');
        }

        $path = $this->pathNormalizer->normalizeDestinationPath($parts['path'] ?? '/');
        $query = $this->parseQuery((string) ($parts['query'] ?? ''));
        $fragment = isset($parts['fragment']) ? (string) $parts['fragment'] : null;
        $internal = $this->internalHosts($scopeHost, $siteKey)->contains($host);

        if (! $internal && ! config('aura-redirects.allow_external_destinations', false)) {
            throw new InvalidArgumentException('External destinations are disabled.');
        }

        if (! $internal && ! $this->allowedExternalHosts()->contains($host)) {
            throw new InvalidArgumentException('External destination host is not allowlisted.');
        }

        $location = $scheme.'://'.$host.$path
            .($query !== [] ? '?'.Arr::query($query) : '')
            .($fragment !== null && $fragment !== '' ? '#'.$fragment : '');

        return new NormalizedDestination(
            location: $location,
            normalizedTarget: $internal ? $host.'|'.$path : $scheme.'://'.$host.$path,
            internal: $internal,
            type: $internal ? 'internal_url' : 'external_url',
            host: $host,
            path: $path,
            queryParameters: $query,
            fragment: $fragment,
        );
    }

    /**
     * @return Collection<int, non-falsy-string>
     */
    private function allowedExternalHosts(): Collection
    {
        return collect((array) config('aura-redirects.allowed_external_hosts', []))
            ->map(fn (string $host): string => RedirectScope::normalizeHost($host))
            ->filter()
            ->map(fn (string $host): string => $host)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function internalHosts(?string $scopeHost, ?string $siteKey): Collection
    {
        $hosts = collect();

        $normalizedScopeHost = RedirectScope::normalizeHost($scopeHost);

        if ($normalizedScopeHost !== '') {
            $hosts->push($normalizedScopeHost);
        }

        $sites = (array) config('aura-redirects.resolver.sites', []);

        if ($siteKey !== null && isset($sites[$siteKey])) {
            foreach ((array) ($sites[$siteKey]['hosts'] ?? []) as $siteHost) {
                $siteHost = RedirectScope::normalizeHost($siteHost);

                if ($siteHost !== '') {
                    $hosts->push($siteHost);
                }
            }
        }

        $appHost = RedirectScope::normalizeHost(parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($appHost !== '') {
            $hosts->push($appHost);
        }

        return $hosts->filter()->unique()->values();
    }

    /**
     * @return array<string, scalar|array|null>
     */
    private function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        parse_str($query, $parameters);

        return is_array($parameters) ? $parameters : [];
    }

    private function normalizeInternalPath(string $destination, ?string $scopeHost): NormalizedDestination
    {
        $parts = parse_url($destination);

        if ($parts === false) {
            throw new InvalidArgumentException('Destination path is malformed.');
        }

        $path = $this->pathNormalizer->normalizeDestinationPath($parts['path'] ?? '/');
        $query = $this->parseQuery((string) ($parts['query'] ?? ''));
        $fragment = isset($parts['fragment']) ? (string) $parts['fragment'] : null;
        $location = $path
            .($query !== [] ? '?'.Arr::query($query) : '')
            .($fragment !== null && $fragment !== '' ? '#'.$fragment : '');

        return new NormalizedDestination(
            location: $location,
            normalizedTarget: RedirectScope::normalizeHost($scopeHost).'|'.$path,
            internal: true,
            type: 'internal_path',
            host: RedirectScope::normalizeHost($scopeHost),
            path: $path,
            queryParameters: $query,
            fragment: $fragment,
        );
    }
}
