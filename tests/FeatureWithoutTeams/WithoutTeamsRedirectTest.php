<?php

use Aura\Redirects\Models\Redirect;

it('uses the single site fallback without Team metadata', function (): void {
    $manager = createRedirectManager(['view', 'viewAny', 'create', 'update', 'delete', 'forceDelete', 'restore']);
    $this->actingAs($manager);

    $redirect = makeRedirect([
        'source_path' => '/single-site',
        'destination' => '/public-destination',
        'site_key' => '',
        'host' => '',
    ]);

    expect($redirect->team_id)->toBeNull()
        ->and($redirect->site_key)->toBe('default')
        ->and($redirect->host)->toBe('www.example.test');

    $this->get('https://www.example.test/single-site')
        ->assertStatus(301)
        ->assertRedirect('/public-destination');
});

it('keeps redirects fail closed for unknown hosts without teams', function (): void {
    createRedirectManager(['view', 'viewAny', 'create', 'update', 'delete', 'forceDelete', 'restore']);
    makeRedirect([
        'source_path' => '/single-host',
        'destination' => '/public-destination',
    ]);

    $this->get('https://unknown.example.test/single-host')
        ->assertNotFound();

    expect(Redirect::withoutGlobalScopes()->first()->team_id)->toBeNull();
});
