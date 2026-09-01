<?php

use Aura\Base\Resources\Permission;
use Aura\Base\Resources\Role;
use Aura\Base\Resources\Team;
use Aura\Base\Resources\User;
use Aura\Redirects\Models\Redirect;
use Aura\Redirects\Services\RedirectPermissionRegistrar;
use Aura\Redirects\Tests\TestCase;
use Aura\Redirects\Tests\WithoutTeamsTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(WithoutTeamsTestCase::class, RefreshDatabase::class)->in('FeatureWithoutTeams');

uses()->afterEach(function (): void {
    app()->forgetInstance(Aura\Base\Aura::class);
    app()->singleton(Aura\Base\Aura::class);
    Aura\Base\Facades\Aura::clearResolvedInstances();
    Aura\Base\Facades\Aura::flushState();
})->in('Feature', 'FeatureWithoutTeams');

/**
 * @param  array<int, string>  $abilities
 */
function createRedirectManager(array $abilities = ['view', 'viewAny', 'create', 'update', 'restore', 'delete', 'forceDelete', 'scope']): User
{
    $user = User::factory()->create();
    $team = null;

    if (config('aura.teams')) {
        auth()->login($user);
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        Cache::forget("user_{$user->id}_current_team_id");
        app(RedirectPermissionRegistrar::class)->synchronize($team->id);
    } else {
        app(RedirectPermissionRegistrar::class)->synchronize(null);
    }

    $permissionMap = collect($abilities)
        ->mapWithKeys(fn (string $ability): array => ["{$ability}-".Redirect::$slug => true])
        ->all();

    $roleAttributes = [
        'description' => 'Redirect manager',
        'name' => 'Redirect Manager',
        'permissions' => $permissionMap,
        'slug' => 'redirect-manager-'.Str::lower(Str::random(8)),
        'super_admin' => false,
        'type' => 'Role',
    ];

    if (Schema::hasColumn('roles', 'team_id')) {
        $roleAttributes['team_id'] = $team?->id;
    }

    $role = Role::withoutGlobalScopes()->create($roleAttributes);

    if ($team) {
        DB::table('user_role')->updateOrInsert(
            [
                'team_id' => $team->id,
                'user_id' => $user->id,
            ],
            [
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    } else {
        DB::table('user_role')->updateOrInsert(
            [
                'user_id' => $user->id,
            ],
            [
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    auth()->login($user);
    test()->actingAs($user);

    return $user->refresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeRedirect(array $attributes = []): Redirect
{
    $defaults = [
        'source_path' => '/old-path',
        'destination' => '/new-path',
        'redirect_status' => 301,
        'enabled' => true,
        'preserve_query' => false,
        'host' => 'www.example.test',
        'site_key' => 'default',
        'notes' => 'Redirect fixture',
    ];

    if (config('aura.teams') && auth()->check()) {
        $defaults['team_id'] = auth()->user()->current_team_id;
    }

    return Redirect::create(array_merge($defaults, $attributes));
}

function redirectPermissionSlugs(?int $teamId = null): array
{
    return Permission::withoutGlobalScopes()
        ->where('group', app(Redirect::class)->pluralName())
        ->when(
            Schema::hasColumn('permissions', 'team_id'),
            fn ($query) => $query->when(
                $teamId === null,
                fn ($query) => $query->whereNull('team_id'),
                fn ($query) => $query->where('team_id', $teamId),
            ),
        )
        ->pluck('slug')
        ->all();
}
