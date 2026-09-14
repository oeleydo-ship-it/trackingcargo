<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Driver;
use App\Models\Vehicle;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class DriverAndVehicleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_create_a_driver_linked_to_an_existing_user(): void
    {
        $company = $this->createCompany('DVA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['drivers.view', 'drivers.manage']);
        $driverUser = $this->createUser($company, $branch);

        $this->actingAs($actor)
            ->post('/drivers', ['user_id' => $driverUser->getKey(), 'branch_id' => $branch->getKey()])
            ->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            self::assertSame(1, Driver::query()->where('user_id', $driverUser->getKey())->count());
        } finally {
            $context->forget();
        }
    }

    public function test_a_user_cannot_be_linked_as_a_driver_twice(): void
    {
        $company = $this->createCompany('DVB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['drivers.view', 'drivers.manage']);
        $driverUser = $this->createUser($company, $branch);

        $this->actingAs($actor)->post('/drivers', ['user_id' => $driverUser->getKey()])->assertRedirect();

        $this->actingAs($actor)
            ->post('/drivers', ['user_id' => $driverUser->getKey()])
            ->assertSessionHasErrors('user_id');
    }

    public function test_a_user_without_permission_cannot_create_a_vehicle(): void
    {
        $company = $this->createCompany('DVC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)
            ->post('/vehicles', ['registration_number' => 'V-001', 'type' => 'van'])
            ->assertForbidden();
    }

    public function test_a_vehicle_registration_number_must_be_unique_within_a_company(): void
    {
        $company = $this->createCompany('DVD');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['vehicles.view', 'vehicles.manage']);

        $this->actingAs($actor)->post('/vehicles', ['registration_number' => 'V-001', 'type' => 'van'])->assertRedirect();

        $this->actingAs($actor)
            ->post('/vehicles', ['registration_number' => 'V-001', 'type' => 'truck'])
            ->assertSessionHasErrors('registration_number');
    }

    public function test_a_company_cannot_view_another_companys_drivers(): void
    {
        $companyA = $this->createCompany('DVE');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actorA = $this->createUser($companyA, $branchA);
        $this->grantPermissions($actorA, ['drivers.view']);

        $companyB = $this->createCompany('DVF');
        $branchB = $this->createBranch($companyB, 'MNL');
        $actorB = $this->createUser($companyB, $branchB);
        $this->grantPermissions($actorB, ['drivers.view', 'drivers.manage']);
        $driverUserB = $this->createUser($companyB, $branchB);
        $this->actingAs($actorB)->post('/drivers', ['user_id' => $driverUserB->getKey()]);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $companyB->getKey());
        try {
            $driver = Driver::query()->where('user_id', $driverUserB->getKey())->firstOrFail();
        } finally {
            $context->forget();
        }

        $this->actingAs($actorA)
            ->patch("/drivers/{$driver->getKey()}", ['phone' => '12345'])
            ->assertNotFound();
    }

    public function test_vehicle_capacity_can_be_updated(): void
    {
        $company = $this->createCompany('DVG');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['vehicles.view', 'vehicles.manage']);
        $this->actingAs($actor)->post('/vehicles', ['registration_number' => 'V-100', 'type' => 'truck', 'capacity_kg' => 1000]);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $vehicle = Vehicle::query()->where('registration_number', 'V-100')->firstOrFail();
        } finally {
            $context->forget();
        }

        $this->actingAs($actor)
            ->patch("/vehicles/{$vehicle->getKey()}", ['capacity_kg' => 1500, 'status' => 'maintenance'])
            ->assertRedirect();

        $context->resolveCompany((int) $company->getKey());
        try {
            $vehicle->refresh();
            self::assertEqualsWithDelta(1500.0, (float) $vehicle->capacity_kg, 0.001);
            self::assertSame('maintenance', $vehicle->status->value);
        } finally {
            $context->forget();
        }
    }
}
