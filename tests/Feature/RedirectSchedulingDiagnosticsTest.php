<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('does not match disabled, future, or expired redirects', function (): void {
    createRedirectManager();

    makeRedirect([
        'source_path' => '/future',
        'destination' => '/new-path',
        'starts_at' => CarbonImmutable::now()->addHour(),
    ]);

    makeRedirect([
        'source_path' => '/expired',
        'destination' => '/new-path',
        'ends_at' => CarbonImmutable::now()->subHour(),
    ]);

    makeRedirect([
        'source_path' => '/disabled',
        'destination' => '/new-path',
        'enabled' => false,
    ]);

    $this->get('https://www.example.test/future')->assertNotFound();
    $this->get('https://www.example.test/expired')->assertNotFound();
    $this->get('https://www.example.test/disabled')->assertNotFound();
});

it('reports actionable diagnostics and exits non zero on errors', function (): void {
    createRedirectManager();

    $redirect = makeRedirect([
        'source_path' => '/warn-me',
        'destination' => '/missing-destination',
    ]);

    DB::table('aura_redirects')->insert([
        'user_id' => $redirect->user_id,
        'team_id' => $redirect->team_id,
        'source_path' => '/warn-me',
        'normalized_source' => '/warn-me',
        'destination' => '/public-destination',
        'destination_type' => 'internal_path',
        'destination_host' => 'www.example.test',
        'destination_path' => '/public-destination',
        'destination_query' => null,
        'destination_fragment' => null,
        'normalized_destination' => 'www.example.test|/public-destination',
        'redirect_status' => 301,
        'enabled' => true,
        'preserve_query' => false,
        'host' => 'www.example.test',
        'site_key' => 'default',
        'scope_hash' => $redirect->scope_hash,
        'active_source_key' => '/warn-me',
        'notes' => 'Forced duplicate for diagnostics',
        'starts_at' => null,
        'ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $exitCode = Artisan::call('aura-redirects:validate');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('conflict')
        ->and($output)->toContain('unreachable_internal')
        ->and($output)->toContain('warn-me')
        ->and($output)->not->toBe('');
});

it('can warm the redirect cache from the validate command', function (): void {
    createRedirectManager();
    makeRedirect([
        'source_path' => '/cache-warm',
        'destination' => '/public-destination',
    ]);

    $exitCode = Artisan::call('aura-redirects:validate', ['--warm-cache' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Warmed 1 redirect cache entries.');
});
