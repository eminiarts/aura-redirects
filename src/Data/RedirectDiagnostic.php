<?php

namespace Aura\Redirects\Data;

final readonly class RedirectDiagnostic
{
    /**
     * @param  array<int, int>  $redirectIds
     */
    public function __construct(
        public string $code,
        public string $level,
        public string $message,
        public array $redirectIds = [],
    ) {}
}
