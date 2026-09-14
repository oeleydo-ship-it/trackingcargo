<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class WarehouseManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_create_a_warehouse_with_a_zone_and_location(): void
    {
        $company = $this->createCompany('WMA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['warehouses.view', 'warehouses.manage']);

        $this->actingAs($actor)
            ->post('/warehouses', ['code' => 'WH1', 'name' => 'Main Warehouse', 'branch_id' => $branch->getKey()])
            ->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $warehouse = Warehouse::query()->where('code', 'WH1')->firstOrFail();
        } finally {
            $context->forget();
        }

        $this->actingAs($actor)
            ->post("/warehouses/{$warehouse->getKey()}/zones", ['code' => 'RCV', 'name' => 'Receiving', 'type' => 'receiving'])
            ->assertRedirect();

        $context->resolveCompany((int) $company->getKey());
        try {
            $zone = $warehouse->zones()->where('code', 'RCV')->firstOrFail();
        } finally {
            $context->forget();
        }

        $this->actingAs($actor)
            ->post("/warehouses/{$warehouse->getKey()}/zones/{$zone->getKey()}/locations", ['code' => 'RCV-01'])
            ->assertRedirect();

        $context->resolveCompany((int) $company->getKey());
        try {
            self::assertSame(1, $zone->locations()->count());
        } finally {
            $context->forget();
        }
    }

    public function test_a_user_without_permission_cannot_create_a_warehouse(): void
    {
        $company = $this->createCompany('WMB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)
            ->post('/warehouses', ['code' => 'WH1', 'name' => 'Main Warehouse'])
            ->assertForbidden();
    }

    public function test_a_company_cannot_view_another_companys_warehouse(): void
    {
        $companyA = $this->createCompany('WMC');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actorA = $this->createUser($companyA, $branchA);
        $this->grantPermissions($actorA, ['warehouses.view', 'warehouses.manage']);
        $this->actingAs($actorA)->post('/warehouses', ['code' => 'WH1', 'name' => 'Warehouse A']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $companyA->getKey());
        try {
            $warehouse = Warehouse::query()->where('code', 'WH1')->firstOrFail();
        } finally {
            $context->forget();
        }

        $companyB = $this->createCompany('WMD');
        $branchB = $this->createBranch($companyB, 'MNL');
        $actorB = $this->createUser($companyB, $branchB);
        $this->grantPermissions($actorB, ['warehouses.view']);

        $this->actingAs($actorB)
            ->get("/warehouses/{$warehouse->getKey()}")
            ->assertNotFound();
    }

    public function test_a_warehouse_code_must_be_unique_within_a_company(): void
    {
        $company = $this->createCompany('WME');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['warehouses.view', 'warehouses.manage']);

        $this->actingAs($actor)->post('/warehouses', ['code' => 'WH1', 'name' => 'First'])->assertRedirect();

        $this->actingAs($actor)
            ->post('/warehouses', ['code' => 'WH1', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('code');
    }
}
