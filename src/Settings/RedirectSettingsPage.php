<?php

namespace Aura\Redirects\Settings;

use Aura\Base\Fields\Boolean;
use Aura\Base\Fields\Panel;
use Aura\Base\Fields\Textarea;
use Aura\Base\Settings\SettingsPage;

final class RedirectSettingsPage
{
    public static function make(): SettingsPage
    {
        return new SettingsPage(
            slug: 'redirects',
            title: 'Redirects',
            fields: [
                [
                    'name' => 'Global redirect rules',
                    'type' => Panel::class,
                    'slug' => 'redirects-global-rules-panel',
                ],
                [
                    'name' => 'Allow external destinations',
                    'type' => Boolean::class,
                    'slug' => 'redirects-allow-external-destinations',
                    'instructions' => 'When disabled, redirects may only target this site or a configured site host.',
                    'validation' => 'boolean',
                ],
                [
                    'name' => 'Allowed external hosts',
                    'type' => Textarea::class,
                    'slug' => 'redirects-allowed-external-hosts',
                    'instructions' => 'One hostname per line. External redirects must be enabled and their host must appear here.',
                    'validation' => 'nullable|string|max:10000',
                ],
            ],
            icon: 'redirect',
            description: 'Configure rules that apply to every redirect managed by this plugin.',
            order: 30,
            defaults: [
                'redirects-allow-external-destinations' => config('aura-redirects.allow_external_destinations', false),
                'redirects-allowed-external-hosts' => implode("\n", (array) config('aura-redirects.allowed_external_hosts', [])),
            ],
        );
    }
}
