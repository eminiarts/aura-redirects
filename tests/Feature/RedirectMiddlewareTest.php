<?php

use Aura\Redirects\Jobs\RecordRedirectHit;
use Aura\Redirects\Support\RedirectScope;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

dataset('redirect-statuses', [301, 302, 307, 308]);

it('redirects exact GET requests with the configured status', function (int $status): void {
    createRedirectManager();
    makeRedirect([
        'source_path' => '/old-path',
        'destination' => '/new-path',
        'redirect_status' => $status,
    ]);

    $this->get('https://www.example.test/old-path')
        ->assertStatus($status)
        ->assertRedirect('/new-path');
})->with('redirect-statuses');

it('redirects HEAD requests without a body', function (): void {
    createRedirectManager();
    makeRedirect([
        'source_path' => '/old-path',
        'destination' => '/head-target',
    ]);

    $response = $this->call('HEAD', 'https://www.example.test/old-path');

    $response->assertStatus(301)->assertRedirect('/head-target');
    expect($response->getContent())->toBe('');
});

it('preserves incoming query strings without overriding destination parameters by default', function (): void {
    createRedirectManager();
    makeRedirect([
        'source_path' => '/search',
        'destination' => '/search-target?lang=en',
        'preserve_query' => true,
    ]);

    $this->get('https://www.example.test/search?utm_source=newsletter&lang=fr')
        ->assertStatus(301)
        ->assertRedirect('/search-target?utm_source=newsletter&lang=en');
});

it('ignores redirect records for protected web routes and non GET or HEAD requests', function (): void {
    createRedirectManager();

    DB::table('aura_redirects')->insert([
        'user_id' => auth()->id(),
        'team_id' => auth()->user()?->current_team_id,
        'source_path' => '/admin/healthcheck',
        'normalized_source' => '/admin/healthcheck',
        'destination' => '/new-path',
        'destination_type' => 'internal_path',
        'destination_host' => 'www.example.test',
        'destination_path' => '/new-path',
        'destination_query' => null,
        'destination_fragment' => null,
        'normalized_destination' => 'www.example.test|/new-path',
        'redirect_status' => 301,
        'enabled' => true,
        'preserve_query' => false,
        'host' => 'www.example.test',
        'site_key' => 'default',
        'scope_hash' => RedirectScope::hash((int) auth()->user()?->current_team_id, 'default', 'www.example.test'),
        'active_source_key' => '/admin/healthcheck',
        'notes' => 'Tampered protected redirect for fail-closed test',
        'starts_at' => null,
        'ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    makeRedirect([
        'source_path' => '/submit',
        'destination' => '/new-path',
    ]);

    $this->get('https://www.example.test/admin/healthcheck')
        ->assertOk()
        ->assertSeeText('admin-ok');

    $this->post('https://www.example.test/submit')
        ->assertOk()
        ->assertSeeText('submitted');
});

it('fails closed when the host cannot be resolved', function (): void {
    createRedirectManager();
    makeRedirect([
        'source_path' => '/old-path',
        'destination' => '/new-path',
    ]);

    $this->get('https://unknown.example.test/old-path')
        ->assertOk()
        ->assertSeeText('old-path');
});

it('dispatches hit recording after the redirect response is created', function (): void {
    Bus::fake();

    createRedirectManager();
    makeRedirect();

    $this->get('https://www.example.test/old-path')
        ->assertStatus(301)
        ->assertRedirect('/new-path');

    Bus::assertDispatchedAfterResponse(RecordRedirectHit::class);
});
