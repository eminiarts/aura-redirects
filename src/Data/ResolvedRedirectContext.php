<?php

namespace Aura\Redirects\Data;

use Aura\Redirects\Support\RedirectScope;

final readonly class ResolvedRedirectContext
{
    public function __construct(
        public string $siteKey,
        public string $host,
        public ?int $teamId,
        public string $timezone,
    ) {}

    public function scopeHash(): string
    {
        return RedirectScope::hash($this->teamId, $this->siteKey, $this->host);
    }
}
