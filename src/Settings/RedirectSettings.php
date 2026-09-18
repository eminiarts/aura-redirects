<?php

namespace Aura\Redirects\Settings;

use Aura\Base\Facades\Aura;
use Aura\Redirects\Support\RedirectScope;

final class RedirectSettings
{
    /** @return list<string> */
    public function allowedExternalHosts(): array
    {
        $configured = config('aura-redirects.allowed_external_hosts', []);
        $value = $this->setting('redirects-allowed-external-hosts', $configured);

        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return collect((array) $value)
            ->map(fn (mixed $host): string => RedirectScope::normalizeHost(is_scalar($host) ? (string) $host : null))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function allowsExternalDestinations(): bool
    {
        return filter_var(
            $this->setting(
                'redirects-allow-external-destinations',
                config('aura-redirects.allow_external_destinations', false),
            ),
            FILTER_VALIDATE_BOOL,
        );
    }

    private function setting(string $key, mixed $fallback): mixed
    {
        $aura = Aura::getFacadeRoot();

        if (! is_object($aura) || ! method_exists($aura, 'setting')) {
            return $fallback;
        }

        return $aura->setting($key, $fallback);
    }
}
