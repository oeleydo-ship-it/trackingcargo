<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Permission;

/**
 * The permission rows from config/permissions.php.
 *
 * Roles are granted permissions by id, so a role created before these rows
 * exist silently ends up with none. Anything that builds a role outside
 * AuthorizationSeeder — first-run setup, public workspace sign-up — calls this
 * first rather than depending on a deploy having seeded.
 */
final class PermissionCatalog
{
    /**
     * Creates any catalog permission that is missing. Existing rows are left
     * untouched, so a renamed permission in the database is not reset.
     */
    public static function ensureInstalled(): void
    {
        foreach (config('permissions.catalog') as $slug => [$name, $group, $platformOnly]) {
            Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group, 'platform_only' => $platformOnly],
            );
        }
    }
}
