<?php

namespace Aura\Redirects\Data;

final readonly class NormalizedDestination
{
    /**
     * @param  array<string, scalar|array|null>  $queryParameters
     */
    public function __construct(
        public string $location,
        public string $normalizedTarget,
        public bool $internal,
        public string $type,
        public ?string $host,
        public string $path,
        public array $queryParameters,
        public ?string $fragment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'location' => $this->location,
            'normalized_target' => $this->normalizedTarget,
            'internal' => $this->internal,
            'type' => $this->type,
            'host' => $this->host,
            'path' => $this->path,
            'query_parameters' => $this->queryParameters,
            'fragment' => $this->fragment,
        ];
    }
}
