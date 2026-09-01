<?php

namespace Aura\Redirects\Services;

use Aura\Base\Resources\Team;
use Aura\Redirects\Contracts\ResolvesRedirectContext;
use Aura\Redirects\Data\ResolvedRedirectContext;
use Aura\Redirects\Support\RedirectScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ConfiguredRedirectContextResolver implements ResolvesRedirectContext
{
    public function resolve(Request $request): ?ResolvedRedirectContext
    {
        $host = RedirectScope::normalizeHost($request->getHost());

        if ($host === '') {
            return null;
        }

        $sites = (array) config('aura-redirects.resolver.sites', []);

        if ($sites !== []) {
            return $this->resolveFromConfiguredSites($sites, $host);
        }

        $appHost = RedirectScope::normalizeHost(parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($appHost === '' || $appHost !== $host) {
            return null;
        }

        if (config('aura.teams')) {
            $teamId = data_get(auth()->user(), 'current_team_id');

            if (! is_int($teamId) && ! ctype_digit((string) $teamId)) {
                $teamId = $this->resolveSoleTeamId();
            }

            if (! is_int($teamId) && ! ctype_digit((string) $teamId)) {
                return null;
            }

            return new ResolvedRedirectContext(
                siteKey: (string) config('aura-redirects.resolver.default_site_key', 'default'),
                host: $host,
                teamId: (int) $teamId,
                timezone: (string) config('app.timezone', 'UTC'),
            );
        }

        return new ResolvedRedirectContext(
            siteKey: (string) config('aura-redirects.resolver.default_site_key', 'default'),
            host: $host,
            teamId: null,
            timezone: (string) config('app.timezone', 'UTC'),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $sites
     */
    private function resolveFromConfiguredSites(array $sites, string $host): ?ResolvedRedirectContext
    {
        $matches = collect($sites)
            ->map(function (array $site, string $siteKey) use ($host): ?array {
                $hosts = collect((array) ($site['hosts'] ?? []))
                    ->map(fn (string $candidate): string => RedirectScope::normalizeHost($candidate))
                    ->filter()
                    ->values();

                if (! $hosts->contains($host)) {
                    return null;
                }

                return [
                    'host' => $host,
                    'site_key' => $siteKey,
                    'team_id' => isset($site['team_id']) ? (int) $site['team_id'] : null,
                    'timezone' => (string) ($site['timezone'] ?? config('app.timezone', 'UTC')),
                ];
            })
            ->filter()
            ->values();

        if ($matches->count() !== 1) {
            return null;
        }

        $match = $matches->first();

        if (! is_array($match)) {
            return null;
        }

        if (config('aura.teams') && ! array_key_exists('team_id', $match)) {
            return null;
        }

        return new ResolvedRedirectContext(
            siteKey: (string) $match['site_key'],
            host: (string) $match['host'],
            teamId: $match['team_id'] === null ? null : (int) $match['team_id'],
            timezone: (string) $match['timezone'],
        );
    }

    private function resolveSoleTeamId(): ?int
    {
        if (! class_exists(Team::class) || ! Schema::hasTable('teams')) {
            return null;
        }

        $teamIds = Team::withoutGlobalScopes()
            ->limit(2)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        return $teamIds->count() === 1 ? (int) $teamIds->first() : null;
    }
}
