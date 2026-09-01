<?php

namespace Aura\Redirects\Support;

use Illuminate\Support\Arr;

final class QueryStringMerger
{
    public function merge(string $location, ?string $incomingQuery): string
    {
        $incomingQuery ??= '';

        if ($incomingQuery === '') {
            return $location;
        }

        $parts = parse_url($location);

        if ($parts === false) {
            return $location;
        }

        parse_str((string) ($parts['query'] ?? ''), $destinationParameters);
        parse_str($incomingQuery, $incomingParameters);

        $merged = config('aura-redirects.query.incoming_overrides_destination', false)
            ? array_replace($destinationParameters, $incomingParameters)
            : array_replace($incomingParameters, $destinationParameters);

        $query = Arr::query($merged);
        $rebuilt = $this->rebuildLocation($parts, $query);

        return $rebuilt === '' ? $location : $rebuilt;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function rebuildLocation(array $parts, string $query): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.$host.$port.$path.($query !== '' ? '?'.$query : '').$fragment;
    }
}
