<?php

use Aura\Redirects\Jobs\RecordRedirectHit;
use Aura\Redirects\Models\RedirectHitStat;

it('stores aggregate hit counts without visitor data', function (): void {
    createRedirectManager();
    $redirect = makeRedirect([
        'source_path' => '/hits',
        'destination' => '/public-destination',
    ]);

    (new RecordRedirectHit(
        redirectId: $redirect->id,
        teamId: $redirect->team_id,
        siteKey: $redirect->site_key,
        host: $redirect->host,
    ))->handle();

    $stat = RedirectHitStat::first();

    expect($stat)->not->toBeNull()
        ->and($stat->redirect_id)->toBe($redirect->id)
        ->and($stat->hit_count)->toBe(1)
        ->and($stat->last_hit_at)->not->toBeNull()
        ->and(array_keys($stat->getAttributes()))->not->toContain('ip_address', 'request_payload');

    expect($redirect->fresh()->hit_count)->toBe(1);
});
