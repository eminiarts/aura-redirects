<?php

use Aura\Redirects\Services\ConfiguredRedirectContextResolver;

return [
    'case_sensitive_paths' => false,

    'allow_external_destinations' => false,

    'allowed_external_hosts' => [],

    'protected_paths' => [
        '/admin',
        '/api',
        '/auth',
        '/broadcasting/auth',
        '/build',
        '/health',
        '/horizon',
        '/livewire',
        '/login',
        '/logout',
        '/password',
        '/queue',
        '/register',
        '/storage',
        '/telescope',
        '/up',
        '/vendor',
        '/_debugbar',
        '/_ignition',
    ],

    'query' => [
        'incoming_overrides_destination' => false,
    ],

    'cache' => [
        'store' => env('AURA_REDIRECTS_CACHE_STORE'),
        'ttl_seconds' => 300,
        'prefix' => 'aura-redirects',
    ],

    'queue' => [
        'connection' => env('AURA_REDIRECTS_QUEUE_CONNECTION'),
        'queue' => env('AURA_REDIRECTS_QUEUE', 'aura-redirects'),
    ],

    'resolver' => [
        'class' => ConfiguredRedirectContextResolver::class,
        'default_site_key' => 'default',
        'sites' => [
            /*
            'marketing' => [
                'hosts' => ['www.example.com'],
                'team_id' => 1,
                'timezone' => 'Europe/Zurich',
            ],
            */
        ],
    ],

    'diagnostics' => [
        'resolve_internal_destinations' => true,
        'max_chain_length' => 8,
    ],

    'schedule' => [
        'validate_cron' => null,
        'warm_cache_during_validation' => false,
    ],
];
