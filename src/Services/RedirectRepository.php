<?php

namespace Aura\Redirects\Services;

use Aura\Redirects\Data\RedirectDefinition;
use Aura\Redirects\Data\ResolvedRedirectContext;
use Aura\Redirects\Models\Redirect;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

class RedirectRepository
{
    public function findActiveMatch(ResolvedRedirectContext $context, string $normalizedSource): ?RedirectDefinition
    {
        try {
            $candidates = Redirect::query()
                ->withoutGlobalScopes()
                ->where('scope_hash', $context->scopeHash())
                ->where('normalized_source', $normalizedSource)
                ->where('enabled', true)
                ->orderByDesc('updated_at')
                ->get()
                ->filter(fn (Redirect $redirect): bool => $this->isActiveAt($redirect, $context->timezone))
                ->values();
        } catch (QueryException $exception) {
            if ($this->causedByMissingRedirectTable($exception)) {
                return null;
            }

            throw $exception;
        }

        if ($candidates->count() !== 1) {
            return null;
        }

        return $this->toDefinition($candidates->first());
    }

    public function isActiveAt(Redirect $redirect, string $timezone): bool
    {
        $now = CarbonImmutable::now($timezone);

        if ($redirect->starts_at !== null && $redirect->starts_at->copy()->timezone($timezone)->isFuture()) {
            return false;
        }

        if ($redirect->ends_at !== null && $now->greaterThanOrEqualTo($redirect->ends_at->copy()->timezone($timezone))) {
            return false;
        }

        return true;
    }

    public function toDefinition(Redirect $redirect): RedirectDefinition
    {
        $queryParameters = [];

        if (is_string($redirect->destination_query) && $redirect->destination_query !== '') {
            $decoded = json_decode($redirect->destination_query, true);

            if (is_array($decoded)) {
                $queryParameters = $decoded;
            }
        }

        return new RedirectDefinition(
            id: (int) $redirect->getKey(),
            sourcePath: (string) $redirect->source_path,
            normalizedSource: (string) $redirect->normalized_source,
            location: $this->locationFor($redirect, $queryParameters),
            status: (int) $redirect->redirect_status,
            preserveQuery: (bool) $redirect->preserve_query,
            internal: in_array($redirect->destination_type, ['internal_path', 'internal_url'], true),
            destinationType: (string) $redirect->destination_type,
            destinationHost: $redirect->destination_host === null ? null : (string) $redirect->destination_host,
            destinationPath: (string) ($redirect->destination_path ?? '/'),
            destinationQueryParameters: $queryParameters,
            destinationFragment: $redirect->destination_fragment === null ? null : (string) $redirect->destination_fragment,
            siteKey: (string) $redirect->site_key,
            host: (string) $redirect->host,
            teamId: $redirect->team_id === null ? null : (int) $redirect->team_id,
            scopeHash: (string) $redirect->scope_hash,
        );
    }

    /**
     * @param  array<string, scalar|array|null>  $queryParameters
     */
    private function locationFor(Redirect $redirect, array $queryParameters): string
    {
        $query = $queryParameters === [] ? '' : '?'.http_build_query($queryParameters);
        $fragment = $redirect->destination_fragment ? '#'.$redirect->destination_fragment : '';

        if ($redirect->destination_type === 'internal_path') {
            return (string) $redirect->destination_path.$query.$fragment;
        }

        if ($redirect->destination_host === null) {
            return (string) $redirect->destination;
        }

        $scheme = str_starts_with((string) $redirect->destination, 'https://') ? 'https://' : 'http://';

        return $scheme.$redirect->destination_host.$redirect->destination_path.$query.$fragment;
    }

    private function causedByMissingRedirectTable(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (! str_contains($message, 'aura_redirects')) {
            return false;
        }

        return str_contains($message, 'no such table')
            || str_contains($message, "doesn't exist")
            || str_contains($message, 'undefined table');
    }
}
