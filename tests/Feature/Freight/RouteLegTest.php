<?php

declare(strict_types=1);

namespace Tests\Feature\Freight;

use App\Enums\RouteLegStatus;
use App\Models\Company;
use App\Models\RouteLeg;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class RouteLegTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_legs_are_assigned_sequential_ordering_automatically(): void
    {
        $company = $this->createCompany('RLA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $shipment = $this->createShipment($company, $branch);

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs", [
            'mode' => 'road', 'origin_location' => 'Dubai warehouse', 'destination_location' => 'Dubai airport',
        ]);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs", [
            'mode' => 'air', 'origin_location' => 'Dubai airport', 'destination_location' => 'Manila airport',
        ]);

        $legs = $this->legsFor($company, $shipment->getKey());
        self::assertSame([1, 2], $legs->pluck('sequence')->all());
        self::assertSame(['road', 'air'], $legs->pluck('mode')->map(fn ($mode) => $mode->value)->all());
    }

    public function test_a_leg_cannot_depart_before_an_earlier_leg_has_arrived(): void
    {
        $company = $this->createCompany('RLB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch);

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs", ['mode' => 'road', 'origin_location' => 'A', 'destination_location' => 'B']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs", ['mode' => 'air', 'origin_location' => 'B', 'destination_location' => 'C']);

        $legs = $this->legsFor($company, $shipment->getKey());
        [$legOne, $legTwo] = [$legs->firstWhere('sequence', 1), $legs->firstWhere('sequence', 2)];

        // Advance leg 1 to "loaded" so it's eligible to depart, but leave it there.
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs/{$legOne->getKey()}/transitions", ['status' => 'loaded']);

        // Leg 2 cannot depart while leg 1 hasn't arrived yet.
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs/{$legTwo->getKey()}/transitions", ['status' => 'loaded']);
        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/legs/{$legTwo->getKey()}/transitions", ['status' => 'departed'])
            ->assertSessionHasErrors('status');

        // Once leg 1 departs and arrives, leg 2 can depart.
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs/{$legOne->getKey()}/transitions", ['status' => 'departed']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs/{$legOne->getKey()}/transitions", ['status' => 'arrived']);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/legs/{$legTwo->getKey()}/transitions", ['status' => 'departed'])
            ->assertSessionDoesntHaveErrors();

        $legTwo->refresh();
        self::assertSame(RouteLegStatus::Departed, $this->freshLeg($company, $legTwo->getKey())->status);
    }

    public function test_a_cancelled_leg_does_not_block_a_later_leg_from_departing(): void
    {
        $company = $this->createCompany('RLC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch);

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs", ['mode' => 'road', 'origin_location' => 'A', 'destination_location' => 'B']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs", ['mode' => 'air', 'origin_location' => 'B', 'destination_location' => 'C']);
        $legs = $this->legsFor($company, $shipment->getKey());
        [$legOne, $legTwo] = [$legs->firstWhere('sequence', 1), $legs->firstWhere('sequence', 2)];

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs/{$legOne->getKey()}/transitions", ['status' => 'cancelled']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/legs/{$legTwo->getKey()}/transitions", ['status' => 'loaded']);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/legs/{$legTwo->getKey()}/transitions", ['status' => 'departed'])
            ->assertSessionDoesntHaveErrors();
    }

    private function legsFor(Company $company, int $shipmentId)
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return RouteLeg::query()->where('shipment_id', $shipmentId)->orderBy('sequence')->get();
        } finally {
            $context->forget();
        }
    }

    private function freshLeg(Company $company, int $legId): RouteLeg
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return RouteLeg::query()->findOrFail($legId);
        } finally {
            $context->forget();
        }
    }
}
