<?php

namespace Aura\Redirects\Services;

class ProtectedPathMatcher
{
    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return collect((array) config('aura-redirects.protected_paths', []))
            ->map(function (string $path): string {
                $path = '/'.trim($path, '/');

                return $path === '//' ? '/' : rtrim($path, '/');
            })
            ->unique()
            ->values()
            ->all();
    }

    public function matches(string $path): bool
    {
        foreach ($this->all() as $protectedPath) {
            if ($path === $protectedPath || str_starts_with($path, $protectedPath.'/')) {
                return true;
            }
        }

        return false;
    }
}
