<?php

namespace Aura\Redirects\Services;

use Aura\Redirects\Data\NormalizedDestination;
use Aura\Redirects\Models\Redirect;
use Aura\Redirects\Support\RedirectScope;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RedirectValidator
{
    public function __construct(
        private readonly RedirectDestinationNormalizer $destinationNormalizer,
        private readonly RedirectPathNormalizer $pathNormalizer,
        private readonly ProtectedPathMatcher $protectedPathMatcher,
    ) {}

    public function prepareAndValidate(Redirect $redirect): void
    {
        $redirect->source_path = $this->normalizeSourcePath($redirect);
        $this->normalizeScope($redirect);
        $redirect->redirect_status = $this->normalizeStatus($redirect->redirect_status);

        $destination = $this->normalizeDestination($redirect);

        $redirect->normalized_source = $redirect->source_path;
        $redirect->scope_hash = RedirectScope::hash($redirect->team_id, $redirect->site_key, $redirect->host);
        $redirect->active_source_key = $redirect->enabled ? $redirect->normalized_source : null;
        $redirect->destination_type = $destination->type;
        $redirect->destination_host = $destination->host;
        $redirect->destination_path = $destination->path;
        $redirect->destination_query = $destination->queryParameters === [] ? null : json_encode($destination->queryParameters, JSON_THROW_ON_ERROR);
        $redirect->destination_fragment = $destination->fragment;
        $redirect->normalized_destination = $destination->normalizedTarget;

        if ($this->protectedPathMatcher->matches($redirect->normalized_source)) {
            throw ValidationException::withMessages([
                'source_path' => 'Protected application routes cannot be redirected.',
            ]);
        }

        if ($this->createsSelfRedirect($redirect, $destination)) {
            throw ValidationException::withMessages([
                'destination' => 'Destination resolves to the same normalized path as the source.',
            ]);
        }

        if ($this->hasDuplicateActiveSource($redirect)) {
            throw ValidationException::withMessages([
                'source_path' => 'An enabled redirect for this exact host, site, and source path already exists.',
            ]);
        }

        if ($this->wouldCreateKnownCycle($redirect, $destination)) {
            throw ValidationException::withMessages([
                'destination' => 'Destination creates a known redirect cycle in this Team/site scope.',
            ]);
        }
    }

    private function createsSelfRedirect(Redirect $redirect, NormalizedDestination $destination): bool
    {
        if (! $destination->internal) {
            return false;
        }

        $destinationHost = RedirectScope::normalizeHost($destination->host ?: $redirect->host);

        return $destinationHost === RedirectScope::normalizeHost($redirect->host)
            && $destination->path === $redirect->normalized_source;
    }

    private function hasDuplicateActiveSource(Redirect $redirect): bool
    {
        if (! $redirect->enabled) {
            return false;
        }

        $query = Redirect::query()
            ->withoutGlobalScopes()
            ->where('scope_hash', $redirect->scope_hash)
            ->where('active_source_key', $redirect->normalized_source);

        if ($redirect->exists) {
            $query->whereKeyNot($redirect->getKey());
        }

        return $query->exists();
    }

    private function normalizeDestination(Redirect $redirect): NormalizedDestination
    {
        try {
            return $this->destinationNormalizer->normalize(
                (string) $redirect->destination,
                (string) $redirect->host,
                (string) $redirect->site_key,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'destination' => $exception->getMessage(),
            ]);
        }
    }

    private function normalizeScope(Redirect $redirect): void
    {
        $sites = (array) config('aura-redirects.resolver.sites', []);
        $siteKey = trim((string) $redirect->site_key);
        $host = RedirectScope::normalizeHost($redirect->host);

        if ($siteKey === '' && $host !== '' && $sites !== []) {
            $siteKey = $this->inferSiteKeyFromHost($host, $sites) ?? '';
        }

        if ($siteKey === '' && $sites === []) {
            $siteKey = (string) config('aura-redirects.resolver.default_site_key', 'default');
        }

        if ($host === '' && $sites !== [] && $siteKey !== '' && isset($sites[$siteKey])) {
            $configuredHosts = collect((array) ($sites[$siteKey]['hosts'] ?? []))
                ->map(fn (string $candidate): string => RedirectScope::normalizeHost($candidate))
                ->filter()
                ->values();

            if ($configuredHosts->count() === 1) {
                $host = (string) $configuredHosts->first();
            }
        }

        if ($host === '') {
            $host = RedirectScope::normalizeHost(parse_url((string) config('app.url'), PHP_URL_HOST));
        }

        if ($siteKey === '' || $host === '') {
            throw ValidationException::withMessages([
                'site_key' => 'Redirects require an explicit site key and host scope.',
            ]);
        }

        if ($sites !== []) {
            if (! array_key_exists($siteKey, $sites)) {
                throw ValidationException::withMessages([
                    'site_key' => 'Unknown site key for the configured redirect resolver.',
                ]);
            }

            $configuredHosts = collect((array) ($sites[$siteKey]['hosts'] ?? []))
                ->map(fn (string $candidate): string => RedirectScope::normalizeHost($candidate))
                ->filter()
                ->values();

            if (! $configuredHosts->contains($host)) {
                throw ValidationException::withMessages([
                    'host' => 'Host does not belong to the selected site key.',
                ]);
            }

            if (config('aura.teams') && isset($sites[$siteKey]['team_id'])) {
                $siteTeamId = (int) $sites[$siteKey]['team_id'];

                if ($redirect->team_id === null) {
                    $redirect->team_id = $siteTeamId;
                }

                if ((int) $redirect->team_id !== $siteTeamId) {
                    throw ValidationException::withMessages([
                        'team_id' => 'Redirect Team does not match the configured Team for this site.',
                    ]);
                }
            }
        }

        if (! config('aura.teams')) {
            $redirect->team_id = null;
        }

        if (config('aura.teams') && $redirect->team_id === null) {
            $redirect->team_id = data_get(auth()->user(), 'current_team_id');
        }

        if (config('aura.teams') && $redirect->team_id === null) {
            throw ValidationException::withMessages([
                'team_id' => 'Teams mode requires an explicit Team scope for redirects.',
            ]);
        }

        $redirect->site_key = $siteKey;
        $redirect->host = $host;
    }

    private function normalizeSourcePath(Redirect $redirect): string
    {
        try {
            return $this->pathNormalizer->normalizeSource((string) $redirect->source_path);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'source_path' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $sites
     */
    private function inferSiteKeyFromHost(string $host, array $sites): ?string
    {
        $matches = collect($sites)
            ->filter(function (array $site) use ($host): bool {
                return collect((array) ($site['hosts'] ?? []))
                    ->map(fn (string $candidate): string => RedirectScope::normalizeHost($candidate))
                    ->contains($host);
            })
            ->keys()
            ->values();

        return $matches->count() === 1 ? (string) $matches->first() : null;
    }

    private function normalizeStatus(mixed $status): int
    {
        $normalized = (int) $status;

        if (! in_array($normalized, [301, 302, 307, 308], true)) {
            throw ValidationException::withMessages([
                'redirect_status' => 'Redirect status must be 301, 302, 307, or 308.',
            ]);
        }

        return $normalized;
    }

    private function wouldCreateKnownCycle(Redirect $redirect, NormalizedDestination $destination): bool
    {
        if (! $redirect->enabled || ! $destination->internal) {
            return false;
        }

        $records = Redirect::query()
            ->withoutGlobalScopes()
            ->where('site_key', $redirect->site_key)
            ->where('enabled', true)
            ->when(
                $redirect->team_id === null,
                fn ($query) => $query->whereNull('team_id'),
                fn ($query) => $query->where('team_id', $redirect->team_id),
            )
            ->when($redirect->exists, fn ($query) => $query->whereKeyNot($redirect->getKey()))
            ->get();

        $edges = [];

        foreach ($records as $record) {
            if (! is_string($record->destination_path) || $record->destination_path === '') {
                continue;
            }

            if (! in_array($record->destination_type, ['internal_path', 'internal_url'], true)) {
                continue;
            }

            $edges[$record->host.'|'.$record->normalized_source] = ($record->destination_host ?: $record->host).'|'.$record->destination_path;
        }

        $currentNode = $redirect->host.'|'.$redirect->normalized_source;
        $nextNode = RedirectScope::normalizeHost($destination->host ?: $redirect->host).'|'.$destination->path;
        $visited = [$currentNode => true];

        for ($depth = 0; $depth < 24; $depth++) {
            if ($nextNode === $currentNode || isset($visited[$nextNode])) {
                return true;
            }

            $visited[$nextNode] = true;

            if (! array_key_exists($nextNode, $edges)) {
                return false;
            }

            $nextNode = $edges[$nextNode];
        }

        return true;
    }
}
