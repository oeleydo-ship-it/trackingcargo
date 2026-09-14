<?php

declare(strict_types=1);

namespace Tests\Feature\Customs;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomsClearance;
use App\Models\CustomsClearanceEvent;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomsClearanceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_opening_a_clearance_transitions_the_shipment_to_at_customs(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CCA', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances", ['customs_office' => 'Dubai Customs'])
            ->assertRedirect();

        $this->withTenant($company, function () use ($shipment): void {
            $shipment->refresh();
            self::assertSame('at_customs', $shipment->status);
            self::assertSame(1, CustomsClearance::query()->where('shipment_id', $shipment->getKey())->count());
        });
    }

    public function test_a_clearance_cannot_be_opened_when_the_shipment_status_does_not_allow_at_customs(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CCB', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'draft']);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances", [])
            ->assertSessionHasErrors('status');
    }

    public function test_a_shipment_cannot_have_two_open_clearances_at_once(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CCC', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances", [])->assertRedirect();

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances", [])
            ->assertSessionHasErrors('shipment');
    }

    public function test_retrying_a_transition_with_the_same_idempotency_key_does_not_duplicate_the_event_or_reapply_the_shipment_transition(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CCD', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances", []);
        $clearance = $this->clearanceFor($company, $shipment->getKey());

        $key = (string) Str::uuid();
        $payload = ['idempotency_key' => $key, 'status' => 'under_review'];

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", $payload)
            ->assertRedirect();
        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", $payload)
            ->assertRedirect();

        $this->withTenant($company, function () use ($key): void {
            self::assertSame(1, CustomsClearanceEvent::query()->where('idempotency_key', $key)->count());
        });
    }

    public function test_clearing_a_clearance_advances_the_shipment_out_of_at_customs(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CCE', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances", []);
        $clearance = $this->clearanceFor($company, $shipment->getKey());

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", [
            'idempotency_key' => (string) Str::uuid(), 'status' => 'under_review',
        ]);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", [
                'idempotency_key' => (string) Str::uuid(), 'status' => 'cleared', 'next_shipment_status' => 'out_for_delivery',
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($shipment, $clearance): void {
            $shipment->refresh();
            $clearance->refresh();
            self::assertSame('out_for_delivery', $shipment->status);
            self::assertNotNull($clearance->cleared_at);
        });
    }

    public function test_rejecting_a_clearance_sends_the_shipment_to_exception(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CCF', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances", []);
        $clearance = $this->clearanceFor($company, $shipment->getKey());

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", [
            'idempotency_key' => (string) Str::uuid(), 'status' => 'under_review',
        ]);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", [
                'idempotency_key' => (string) Str::uuid(), 'status' => 'rejected', 'reason' => 'Missing certificate of origin',
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($shipment): void {
            $shipment->refresh();
            self::assertSame('exception', $shipment->status);
        });
    }

    public function test_holding_a_clearance_requires_a_reason(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CCG', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances", []);
        $clearance = $this->clearanceFor($company, $shipment->getKey());
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", [
            'idempotency_key' => (string) Str::uuid(), 'status' => 'under_review',
        ]);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", [
                'idempotency_key' => (string) Str::uuid(), 'status' => 'held',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_an_invalid_transition_is_rejected(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CCH', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances", []);
        $clearance = $this->clearanceFor($company, $shipment->getKey());

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", [
                'idempotency_key' => (string) Str::uuid(), 'status' => 'cleared',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_a_company_cannot_view_another_companys_clearance(): void
    {
        [$companyA, $branchA, $actorA] = $this->setUpTenant('CCI', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $shipmentA = $this->createShipment($companyA, $branchA, ['status' => 'in_transit']);
        $this->actingAs($actorA)->post("/shipments/{$shipmentA->getKey()}/customs-clearances", []);
        $clearanceA = $this->clearanceFor($companyA, $shipmentA->getKey());

        [$companyB, $branchB, $actorB] = $this->setUpTenant('CCJ', ['customs.view']);

        $this->actingAs($actorB)
            ->get("/shipments/{$shipmentA->getKey()}/customs-clearances/{$clearanceA->getKey()}")
            ->assertNotFound();
    }

    public function test_a_user_with_only_view_permission_cannot_open_a_clearance(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CCK', ['customs.view', 'shipments.view']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/customs-clearances", [])
            ->assertForbidden();
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

    private function clearanceFor(Company $company, int $shipmentId): CustomsClearance
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return CustomsClearance::query()->where('shipment_id', $shipmentId)->firstOrFail();
        } finally {
            $context->forget();
        }
    }
}
