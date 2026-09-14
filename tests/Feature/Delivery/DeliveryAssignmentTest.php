<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DeliveryAssignment;
use App\Models\Driver;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class DeliveryAssignmentTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_manually_assigning_a_driver_advances_the_shipment_to_out_for_delivery_once_started(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('DAA', ['deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $driver = $this->createDriver($company, $branch, $actor);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/delivery-assignments", ['driver_id' => $driver->getKey()])
            ->assertRedirect();

        $assignment = $this->assignmentFor($company, $shipment->getKey());
        self::assertSame('assigned', $assignment->status->value);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/transitions", ['status' => 'out_for_delivery'])
            ->assertRedirect();

        $this->withTenant($company, function () use ($shipment, $assignment): void {
            $shipment->refresh();
            $assignment->refresh();
            self::assertSame('out_for_delivery', $shipment->status);
            self::assertSame('out_for_delivery', $assignment->status->value);
        });
    }

    public function test_a_shipment_cannot_have_two_open_assignments_at_once(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('DAB', ['deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $driver = $this->createDriver($company, $branch, $actor);

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments", ['driver_id' => $driver->getKey()])->assertRedirect();

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/delivery-assignments", ['driver_id' => $driver->getKey()])
            ->assertSessionHasErrors('shipment');
    }

    public function test_the_delivered_status_is_unreachable_through_the_generic_transition_endpoint(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('DAC', ['deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $driver = $this->createDriver($company, $branch, $actor);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments", ['driver_id' => $driver->getKey()]);
        $assignment = $this->assignmentFor($company, $shipment->getKey());
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/transitions", ['status' => 'out_for_delivery']);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/transitions", ['status' => 'delivered'])
            ->assertSessionHasErrors('status');
    }

    public function test_without_a_driver_id_the_least_loaded_active_driver_in_the_branch_is_picked(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('DAD', ['deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage', 'shipments.view', 'tracking.update']);
        $busyDriver = $this->createDriver($company, $branch, $actor);
        $freeDriver = $this->createDriver($company, $branch, $actor);

        $busyShipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $this->actingAs($actor)->post("/shipments/{$busyShipment->getKey()}/delivery-assignments", ['driver_id' => $busyDriver->getKey()]);

        $newShipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $this->actingAs($actor)
            ->post("/shipments/{$newShipment->getKey()}/delivery-assignments", [])
            ->assertRedirect();

        $assignment = $this->assignmentFor($company, $newShipment->getKey());
        self::assertSame($freeDriver->getKey(), $assignment->driver_id);
    }

    public function test_only_the_assigned_driver_can_execute_their_own_delivery(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('DAE', ['deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $assignedDriver = $this->createDriver($company, $branch, $actor);
        $otherDriverUser = $this->createUser($company, $branch);
        $this->grantPermissions($otherDriverUser, ['deliveries.view', 'deliveries.execute']);
        $this->actingAs($actor)->post('/drivers', ['user_id' => $otherDriverUser->getKey()]);

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments", ['driver_id' => $assignedDriver->getKey()]);
        $assignment = $this->assignmentFor($company, $shipment->getKey());

        $this->actingAs($otherDriverUser)
            ->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/transitions", ['status' => 'out_for_delivery'])
            ->assertForbidden();

        $this->actingAs($assignedDriver->user)
            ->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/transitions", ['status' => 'out_for_delivery'])
            ->assertRedirect();
    }

    /** @return array{0: Company, 1: Branch, 2: User} */
    private function setUpTenant(string $code, array $permissions): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, $permissions);

        return [$company, $branch, $actor];
    }

    private function createDriver(Company $company, Branch $branch, User $actor): Driver
    {
        $driverUser = $this->createUser($company, $branch);
        $this->grantPermissions($driverUser, ['deliveries.view', 'deliveries.execute']);

        $this->actingAs($actor)->post('/drivers', ['user_id' => $driverUser->getKey(), 'branch_id' => $branch->getKey()]);

        return $this->withTenantReturn($company, fn () => Driver::query()->where('user_id', $driverUser->getKey())->with('user')->firstOrFail());
    }

    private function assignmentFor(Company $company, int $shipmentId): DeliveryAssignment
    {
        return $this->withTenantReturn($company, fn () => DeliveryAssignment::query()->where('shipment_id', $shipmentId)->firstOrFail());
    }

    private function withTenant(Company $company, \Closure $callback): void
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $callback();
        } finally {
            $context->forget();
        }
    }

    private function withTenantReturn(Company $company, \Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
