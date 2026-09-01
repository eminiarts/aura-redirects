<?php

use Aura\Base\Livewire\Resource\Create;
use Aura\Redirects\Models\Redirect;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

it('creates redirects through the Aura resource create component', function (): void {
    $manager = createRedirectManager();

    $this->actingAs($manager);

    livewire(Create::class, ['slug' => Redirect::$slug])
        ->set('form.fields.source_path', '/campaign')
        ->set('form.fields.destination', '/campaign-destination')
        ->set('form.fields.redirect_status', '302')
        ->set('form.fields.enabled', true)
        ->set('form.fields.preserve_query', true)
        ->set('form.fields.host', 'www.example.test')
        ->set('form.fields.site_key', 'default')
        ->set('form.fields.notes', 'Launch campaign redirect')
        ->call('save')
        ->assertHasNoErrors();

    $redirect = Redirect::withoutGlobalScopes()->first();

    expect($redirect)->not->toBeNull()
        ->and($redirect->normalized_source)->toBe('/campaign')
        ->and($redirect->destination_type)->toBe('internal_path')
        ->and($redirect->team_id)->toBe($manager->current_team_id);
});

it('synchronizes Team scoped redirect permissions', function (): void {
    $manager = createRedirectManager();

    expect(redirectPermissionSlugs($manager->current_team_id))->toContain(
        'view-aura-redirect',
        'viewAny-aura-redirect',
        'create-aura-redirect',
        'update-aura-redirect',
        'restore-aura-redirect',
        'delete-aura-redirect',
        'forceDelete-aura-redirect',
        'scope-aura-redirect',
    );
});

it('rejects protected sources, duplicate active sources, unsafe destinations, and known cycles', function (): void {
    createRedirectManager();

    expect(fn () => makeRedirect(['source_path' => '/admin/healthcheck']))
        ->toThrow(ValidationException::class, 'Protected application routes');

    $first = makeRedirect(['source_path' => '/products', 'destination' => '/new-path']);

    expect(fn () => makeRedirect(['source_path' => '/products', 'destination' => '/temporary-target']))
        ->toThrow(ValidationException::class, 'enabled redirect for this exact host');

    expect(fn () => makeRedirect([
        'source_path' => '/bad-external',
        'destination' => 'javascript:alert(1)',
    ]))->toThrow(ValidationException::class, 'Destination scheme must be http or https');

    makeRedirect([
        'source_path' => '/cycle-b',
        'destination' => '/cycle-c',
    ]);

    makeRedirect([
        'source_path' => '/cycle-c',
        'destination' => '/cycle-d',
    ]);

    expect(fn () => makeRedirect([
        'source_path' => '/cycle-d',
        'destination' => '/cycle-b',
    ]))->toThrow(ValidationException::class, 'known redirect cycle');

    expect($first->refresh()->normalized_source)->toBe('/products');
});

it('rejects invalid site scopes against configured hosts', function (): void {
    $manager = createRedirectManager();

    config()->set('aura-redirects.resolver.sites', [
        'marketing' => [
            'hosts' => ['marketing.example.test'],
            'team_id' => $manager->current_team_id,
            'timezone' => 'UTC',
        ],
    ]);

    expect(fn () => makeRedirect([
        'site_key' => 'marketing',
        'host' => 'shop.example.test',
    ]))->toThrow(ValidationException::class, 'Host does not belong to the selected site key');
});
