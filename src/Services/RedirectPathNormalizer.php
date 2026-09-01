<?php

namespace Aura\Redirects\Services;

use Illuminate\Http\Request;
use InvalidArgumentException;

class RedirectPathNormalizer
{
    public function normalizeDestinationPath(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            $path = '/';
        }

        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Internal destinations must use an absolute path.');
        }

        return $this->normalizePath($path, false);
    }

    public function normalizeRequestPath(Request $request): ?string
    {
        $requestUri = (string) ($request->server('REQUEST_URI') ?? $request->getRequestUri());
        $path = parse_url($requestUri, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            $path = '/';
        }

        try {
            return $this->normalizePath($path, false);
        } catch (\Throwable) {
            return null;
        }
    }

    public function normalizeSource(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException('Source path is required.');
        }

        if (str_contains($path, '://') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Source path must be relative to the current host.');
        }

        if (str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Source path cannot include a query string or fragment.');
        }

        return $this->normalizePath($path, true);
    }

    private function normalizePath(string $path, bool $prependLeadingSlash): string
    {
        if (preg_match('/[\r\n\t\f\v ]/', $path) === 1) {
            throw new InvalidArgumentException('Whitespace is not allowed in redirect paths.');
        }

        if ($prependLeadingSlash && ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $normalized = $this->normalizePercentEncoding($path);
        $normalized = preg_replace('#/{2,}#', '/', $normalized) ?? $normalized;

        if (! str_starts_with($normalized, '/')) {
            throw new InvalidArgumentException('Redirect paths must start with a slash.');
        }

        $normalized = $this->rejectTraversalSegments($normalized);

        if ($normalized !== '/' && str_ends_with($normalized, '/')) {
            $normalized = rtrim($normalized, '/');
        }

        if (! config('aura-redirects.case_sensitive_paths', false)) {
            $normalized = $this->lowercasePreservingPercentEncoding($normalized);
        }

        return $normalized === '' ? '/' : $normalized;
    }

    private function lowercasePreservingPercentEncoding(string $path): string
    {
        return preg_replace_callback('/(%[A-F0-9]{2})|([^%]+)/', function (array $matches): string {
            if (($matches[1] ?? '') !== '') {
                return $matches[1];
            }

            return strtolower((string) ($matches[2] ?? ''));
        }, $path) ?? strtolower($path);
    }

    private function normalizePercentEncoding(string $path): string
    {
        $normalized = '';
        $length = strlen($path);

        for ($index = 0; $index < $length; $index++) {
            $character = $path[$index];

            if ($character === '\\') {
                throw new InvalidArgumentException('Backslashes are not allowed in redirect paths.');
            }

            if ($character !== '%') {
                if (ord($character) < 32 || ord($character) === 127) {
                    throw new InvalidArgumentException('Control characters are not allowed in redirect paths.');
                }

                $normalized .= $character;

                continue;
            }

            if ($index + 2 >= $length) {
                throw new InvalidArgumentException('Malformed percent encoding.');
            }

            $hex = strtoupper(substr($path, $index + 1, 2));

            if (! ctype_xdigit($hex)) {
                throw new InvalidArgumentException('Malformed percent encoding.');
            }

            $decoded = chr((int) hexdec($hex));

            if ($decoded === '/' || $decoded === '\\' || $decoded === "\0") {
                throw new InvalidArgumentException('Encoded slashes and null bytes are not allowed.');
            }

            if ($this->isUnreserved($decoded)) {
                $normalized .= $decoded;
            } else {
                $normalized .= '%'.$hex;
            }

            $index += 2;
        }

        return $normalized;
    }

    private function rejectTraversalSegments(string $path): string
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Traversal segments are not allowed in redirect paths.');
            }
        }

        return $path;
    }

    private function isUnreserved(string $character): bool
    {
        return preg_match('/^[A-Za-z0-9\\-._~]$/', $character) === 1;
    }
}
