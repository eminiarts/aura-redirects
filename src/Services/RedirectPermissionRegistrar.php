<?php

namespace Aura\Redirects\Services;

use Aura\Base\Resources\Permission;
use Aura\Base\Resources\Team;
use Aura\Redirects\Models\Redirect;
use Illuminate\Support\Facades\Schema;

class RedirectPermissionRegistrar
{
    public function synchronize(?int $teamId = null): int
    {
        if (! Schema::hasTable('permissions')) {
            return 0;
        }

        if (! config('aura.teams')) {
            return $this->synchronizeTeamPermissions(null);
        }

        if ($teamId !== null) {
            return $this->synchronizeTeamPermissions($teamId);
        }

        if (! class_exists(Team::class) || ! Schema::hasTable('teams')) {
            return 0;
        }

        $created = 0;

        foreach (Team::withoutGlobalScopes()->pluck('id') as $existingTeamId) {
            $created += $this->synchronizeTeamPermissions((int) $existingTeamId);
        }

        return $created;
    }

    private function synchronizeTeamPermissions(?int $teamId): int
    {
        $resource = app(Redirect::class);
        $created = 0;
        $hasTeamColumn = Schema::hasColumn('permissions', 'team_id');

        foreach ([
            'view' => "View {$resource->pluralName()}",
            'viewAny' => "View Any {$resource->pluralName()}",
            'create' => "Create {$resource->pluralName()}",
            'update' => "Update {$resource->pluralName()}",
            'restore' => "Restore {$resource->pluralName()}",
            'delete' => "Delete {$resource->pluralName()}",
            'forceDelete' => "Force Delete {$resource->pluralName()}",
            'scope' => "Scope {$resource->pluralName()}",
        ] as $ability => $name) {
            $attributes = [
                'slug' => "{$ability}-{$resource::$slug}",
            ];

            if ($hasTeamColumn) {
                $attributes['team_id'] = $teamId;
            }

            Permission::withoutGlobalScopes()->updateOrCreate(
                $attributes,
                [
                    'group' => $resource->pluralName(),
                    'name' => $name,
                ],
            );

            $created++;
        }

        return $created;
    }
}
