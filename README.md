# Aura Redirects

Aura Redirects is the free, MIT-licensed Aura CMS plugin for safe HTTP redirect management with exact-path matching, host and Team isolation, cache-aware runtime lookup, diagnostics, and asynchronous hit aggregation.

## Highlights

- Native Aura Resource built entirely from existing Aura fields
- Exact-path redirects only for eligible `GET` and `HEAD` requests
- Safe internal and allowlisted external destinations
- Protected route shielding for Aura admin, auth, API, assets, health, queue, and debug paths
- Team and host scoped matching with fail-closed site resolution
- Query-string preservation controls
- Redirect scheduling with site timezone support
- Loop, chain, conflict, invalid-destination, expired, shadowed, and unreachable-target diagnostics
- Async aggregate hit counters without storing visitor PII
- CLI validation and optional cache warming
- Laravel 12/13, PHP 8.4+, Livewire 4, Aura Base only

## Installation

```bash
composer require eminiarts/aura-redirects
php artisan migrate
```

If your app runs with Aura Teams or multiple public hosts, configure explicit host-to-site resolution before expecting redirects to fire:

```php
// config/aura-redirects.php
'resolver' => [
    'class' => \Aura\Redirects\Services\ConfiguredRedirectContextResolver::class,
    'default_site_key' => 'default',
    'sites' => [
        'marketing' => [
            'hosts' => ['www.example.com'],
            'team_id' => 1,
            'timezone' => 'Europe/Zurich',
        ],
    ],
],
```

## How It Works

Aura Redirects registers one Aura Resource, `Redirect`, backed by package-owned tables:

- `aura_redirects` stores validated redirect definitions
- `aura_redirect_hit_stats` stores aggregate hit counters and last-hit timestamps

The runtime middleware:

1. Accepts only `GET` and `HEAD` requests on the `web` middleware group
2. Normalizes the incoming path
3. Refuses protected paths
4. Resolves host, site key, Team, and timezone
5. Loads one exact redirect definition from an isolated cache key
6. Builds the final destination, preserving incoming query parameters only when configured on that record
7. Queues an after-response hit update that never blocks or breaks the redirect

If host or Team resolution is missing or ambiguous, the middleware does nothing.

## Redirect Resource Fields

- `source_path`
- `destination`
- `redirect_status`
- `enabled`
- `preserve_query`
- `host`
- `site_key`
- `starts_at`
- `ends_at`
- `notes`
- `hit_count`
- `last_hit_at`
- `created_at`
- `updated_at`

## Status Guidance

- `301`: permanent redirect for safe long-lived moves
- `302`: temporary redirect, safest default while changes are still in flux
- `307`: temporary and method-preserving
- `308`: permanent and method-preserving

Aura Redirects itself only matches `GET` and `HEAD`, but `307` and `308` remain available so stored definitions remain semantically correct if reused in tooling or inspected operationally.

## Security Model

- No regex, wildcard, or code-based redirects in V1
- No redirect interception for protected prefixes such as `/admin`, `/login`, `/api`, `/livewire`, `/storage`, `/up`, `/_debugbar`, or `/_ignition`
- External redirects are off by default and require an explicit allowlist
- Protocol-relative URLs, control characters, CRLF injection, encoded slashes, traversal forms, malformed percent encoding, and unsupported schemes are rejected
- No redirect fires when host/site resolution is absent or ambiguous
- Analytics store only counts and timestamps, never IPs or visitor payloads

## CLI

Validate definitions and optionally warm cache:

```bash
php artisan aura-redirects:validate
php artisan aura-redirects:validate --warm-cache
```

The command exits non-zero when error-level diagnostics are found.

## Caching

Redirect lookups are cached per Team, site key, host, and normalized source path. Update, delete, enable, disable, and scope changes bump a scoped cache version so long-running workers and Octane instances do not serve stale redirect definitions.

## Optional Aura SEO Integration

Aura Redirects does not require Aura SEO and never imports it. When both packages are installed, host apps may suggest redirect creation from slug changes or metadata workflows by writing normal `Redirect` records or calling the package validation services directly.

## Development

```bash
composer install
composer test
composer analyse
composer format
```

## Limitations

- Exact paths only
- No bulk import/export in V1
- No automatic slug-change redirects
- No 404 dashboards or crawl analytics
- No non-`GET`/`HEAD` redirect interception
