<?php

namespace Aura\Redirects\Support;

final class RedirectScope
{
    public static function hash(?int $teamId, string $siteKey, string $host): string
    {
        return hash('sha256', implode('|', [
            'team:'.($teamId ?? 'global'),
            'site:'.$siteKey,
            'host:'.$host,
        ]));
    }

    public static function normalizeHost(?string $host): string
    {
        $normalized = strtolower(trim((string) $host));

        if ($normalized === '') {
            return '';
        }

        if (str_contains($normalized, ':')) {
            [$candidate] = explode(':', $normalized, 2);

            return $candidate;
        }

        return $normalized;
    }
}
