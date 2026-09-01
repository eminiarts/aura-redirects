<?php

namespace Aura\Redirects\Services;

use Aura\Redirects\Data\RedirectDefinition;
use Aura\Redirects\Jobs\RecordRedirectHit;
use Illuminate\Support\Facades\Bus;
use Throwable;

class RedirectHitRecorder
{
    public function record(RedirectDefinition $definition): void
    {
        try {
            $job = new RecordRedirectHit(
                redirectId: $definition->id,
                teamId: $definition->teamId,
                siteKey: $definition->siteKey,
                host: $definition->host,
            );

            $connection = config('aura-redirects.queue.connection');
            $queue = config('aura-redirects.queue.queue');

            if (is_string($connection) && $connection !== '') {
                $job->onConnection($connection);
            }

            if (is_string($queue) && $queue !== '') {
                $job->onQueue($queue);
            }

            Bus::dispatchAfterResponse($job);
        } catch (Throwable) {
            // Analytics must never break redirects.
        }
    }
}
