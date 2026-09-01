<?php

namespace Aura\Redirects\Data;

final readonly class RedirectDefinition
{
    /**
     * @param  array<string, scalar|array|null>  $destinationQueryParameters
     */
    public function __construct(
        public int $id,
        public string $sourcePath,
        public string $normalizedSource,
        public string $location,
        public int $status,
        public bool $preserveQuery,
        public bool $internal,
        public string $destinationType,
        public ?string $destinationHost,
        public string $destinationPath,
        public array $destinationQueryParameters,
        public ?string $destinationFragment,
        public string $siteKey,
        public string $host,
        public ?int $teamId,
        public string $scopeHash,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toCachePayload(): array
    {
        return [
            'id' => $this->id,
            'source_path' => $this->sourcePath,
            'normalized_source' => $this->normalizedSource,
            'location' => $this->location,
            'status' => $this->status,
            'preserve_query' => $this->preserveQuery,
            'internal' => $this->internal,
            'destination_type' => $this->destinationType,
            'destination_host' => $this->destinationHost,
            'destination_path' => $this->destinationPath,
            'destination_query_parameters' => $this->destinationQueryParameters,
            'destination_fragment' => $this->destinationFragment,
            'site_key' => $this->siteKey,
            'host' => $this->host,
            'team_id' => $this->teamId,
            'scope_hash' => $this->scopeHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCachePayload(array $payload): self
    {
        return new self(
            id: (int) $payload['id'],
            sourcePath: (string) $payload['source_path'],
            normalizedSource: (string) $payload['normalized_source'],
            location: (string) $payload['location'],
            status: (int) $payload['status'],
            preserveQuery: (bool) $payload['preserve_query'],
            internal: (bool) $payload['internal'],
            destinationType: (string) $payload['destination_type'],
            destinationHost: $payload['destination_host'] === null ? null : (string) $payload['destination_host'],
            destinationPath: (string) $payload['destination_path'],
            destinationQueryParameters: is_array($payload['destination_query_parameters']) ? $payload['destination_query_parameters'] : [],
            destinationFragment: $payload['destination_fragment'] === null ? null : (string) $payload['destination_fragment'],
            siteKey: (string) $payload['site_key'],
            host: (string) $payload['host'],
            teamId: $payload['team_id'] === null ? null : (int) $payload['team_id'],
            scopeHash: (string) $payload['scope_hash'],
        );
    }
}
