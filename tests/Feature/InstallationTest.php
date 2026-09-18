<?php

use Aura\Base\Facades\Aura;
use Aura\Base\Settings\SettingsRegistry;
use Aura\Redirects\Middleware\HandleAuraRedirects;
use Aura\Redirects\Models\Redirect;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;

it('registers the redirect resource and prepends the middleware to the web group', function (): void {
    expect(Aura::getResources())->toContain(Redirect::class);

    $fields = collect(Redirect::getFields())->keyBy('slug');

    expect($fields->keys()->all())->toContain(
        'source_path',
        'destination',
        'redirect_status',
        'enabled',
        'preserve_query',
        'starts_at',
        'ends_at',
        'notes',
        'hit_count',
        'last_hit_at',
    )->not->toContain('host', 'site_key')
        ->and($fields['starts_at']['style']['width'])->toBe('50')
        ->and($fields['ends_at']['style']['width'])->toBe('50')
        ->and(app(SettingsRegistry::class)->has('redirects'))->toBeTrue();

    /** @var Router $router */
    $router = app('router');
    expect($router->getMiddlewareGroups()['web'][0] ?? null)->toBe(HandleAuraRedirects::class);
});

it('fails closed before the redirect tables are migrated', function (): void {
    Schema::drop('aura_redirect_hit_stats');
    Schema::drop('aura_redirects');

    $this->get('/new-path')
        ->assertOk()
        ->assertSeeText('new-path');
});
