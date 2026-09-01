<?php

use Aura\Base\Resources\Team;

it('isolates identical sources across teams and hosts', function (): void {
    $manager = createRedirectManager();
    $teamOne = Team::withoutGlobalScopes()->findOrFail($manager->current_team_id);
    $teamTwo = Team::factory()->create();

    config()->set('aura-redirects.resolver.sites', [
        'marketing-one' => [
            'hosts' => ['one.example.test'],
            'team_id' => $teamOne->id,
            'timezone' => 'UTC',
        ],
        'marketing-two' => [
            'hosts' => ['two.example.test'],
            'team_id' => $teamTwo->id,
            'timezone' => 'UTC',
        ],
    ]);

    makeRedirect([
        'source_path' => '/promo',
        'destination' => '/new-path',
        'host' => 'one.example.test',
        'site_key' => 'marketing-one',
        'team_id' => $teamOne->id,
    ]);

    makeRedirect([
        'source_path' => '/promo',
        'destination' => '/temporary-target',
        'host' => 'two.example.test',
        'site_key' => 'marketing-two',
        'team_id' => $teamTwo->id,
    ]);

    $this->get('https://one.example.test/promo')
        ->assertStatus(301)
        ->assertRedirect('/new-path');

    $this->get('https://two.example.test/promo')
        ->assertStatus(301)
        ->assertRedirect('/temporary-target');
});

it('invalidates cached matches after redirect updates', function (): void {
    createRedirectManager();
    $redirect = makeRedirect([
        'source_path' => '/stale',
        'destination' => '/new-path',
    ]);

    $this->get('https://www.example.test/stale')
        ->assertStatus(301)
        ->assertRedirect('/new-path');

    $redirect->update(['destination' => '/temporary-target']);

    $this->get('https://www.example.test/stale')
        ->assertStatus(301)
        ->assertRedirect('/temporary-target');
});
