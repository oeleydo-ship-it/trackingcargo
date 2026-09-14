<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class RoleManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_company_admin_cannot_create_a_role_with_platform_only_permissions(): void
    {
        $company = $this->createCompany('PLT');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['roles.view', 'roles.manage']);

        $context = app(TenantContext::class);
        $context->resolvePlatformBypass();
        $platformPermission = Permission::query()->create(['slug' => 'system-configuration.manage', 'name' => 'System config', 'group' => 'platform', 'platform_only' => true]);
        $context->forget();

        $this->actingAs($actor)
            ->post('/settings/roles', [
                'name' => 'Sneaky Role',
                'slug' => 'sneaky-role',
                'permissions' => [$platformPermission->getKey()],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('roles', ['slug' => 'sneaky-role']);
    }

    public function test_role_assignment_rejects_a_role_from_another_company(): void
    {
        $companyA = $this->createCompany('RAA');
        $companyB = $this->createCompany('RAB');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['roles.manage', 'users.manage']);
        $targetA = $this->createUser($companyA);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $companyB->getKey());
        $roleB = Role::query()->create(['name' => 'Foreign role', 'slug' => 'foreign-role']);
        $context->forget();

        $this->actingAs($actorA)
            ->post("/settings/users/{$targetA->getKey()}/roles", ['role_id' => $roleB->getKey()])
            ->assertSessionHasErrors('role_id');
    }

    public function test_role_listing_never_includes_another_companys_roles(): void
    {
        $companyA = $this->createCompany('RLA');
        $companyB = $this->createCompany('RLB');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['roles.view']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $companyB->getKey());
        Role::query()->create(['name' => 'Other company role', 'slug' => 'other-company-role']);
        $context->forget();

        $response = $this->actingAs($actorA)->get('/settings/roles');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('roles', 1));
    }

    public function test_a_company_admin_can_rename_a_role_and_resync_its_permissions(): void
    {
        $company = $this->createCompany('RUA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['roles.view', 'roles.manage']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        $role = Role::query()->create(['name' => 'Dispatcher', 'slug' => 'dispatcher']);
        $granted = Permission::query()->firstOrCreate(['slug' => 'shipments.view'], ['name' => 'View shipments', 'group' => 'shipments', 'platform_only' => false]);
        $context->forget();

        $this->actingAs($actor)
            ->patch("/settings/roles/{$role->getKey()}", [
                'name' => 'Senior Dispatcher',
                'slug' => 'senior-dispatcher',
                'permissions' => [$granted->getKey()],
            ])
            ->assertRedirect();

        $role->refresh();
        self::assertSame('Senior Dispatcher', $role->name);
        self::assertSame('senior-dispatcher', $role->slug);
        self::assertSame([$granted->getKey()], $role->permissions()->pluck('permissions.id')->all());
    }

    public function test_a_system_roles_permissions_are_editable_but_its_slug_is_not(): void
    {
        $company = $this->createCompany('RUB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['roles.view', 'roles.manage']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        $role = Role::query()->create(['name' => 'Customer', 'slug' => 'customer', 'is_system' => true]);
        $granted = Permission::query()->firstOrCreate(['slug' => 'shipments.view'], ['name' => 'View shipments', 'group' => 'shipments', 'platform_only' => false]);
        $context->forget();

        $this->actingAs($actor)
            ->patch("/settings/roles/{$role->getKey()}", [
                'name' => 'Portal Customer',
                'slug' => 'renamed-customer',
                'permissions' => [$granted->getKey()],
            ])
            ->assertRedirect();

        $role->refresh();
        self::assertSame('Portal Customer', $role->name);
        self::assertSame('customer', $role->slug);
        self::assertSame([$granted->getKey()], $role->permissions()->pluck('permissions.id')->all());
    }

    public function test_updating_a_role_cannot_smuggle_in_platform_only_permissions(): void
    {
        $company = $this->createCompany('RUC');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['roles.view', 'roles.manage']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        $role = Role::query()->create(['name' => 'Ops', 'slug' => 'ops']);
        $context->resolvePlatformBypass();
        $platformPermission = Permission::query()->create(['slug' => 'system-configuration.manage', 'name' => 'System config', 'group' => 'platform', 'platform_only' => true]);
        $context->forget();

        $this->actingAs($actor)
            ->patch("/settings/roles/{$role->getKey()}", [
                'name' => 'Ops',
                'slug' => 'ops',
                'permissions' => [$platformPermission->getKey()],
            ])
            ->assertSessionHasErrors('permissions');

        self::assertSame(0, $role->permissions()->count());
    }
}
