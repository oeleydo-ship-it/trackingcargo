<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class ShipmentTransitionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_late_status_update_uses_the_event_date_but_keeps_the_recording_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $company = $this->createCompany('LATE');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.update']);
        $shipment = $this->draftShipment($company, $branch, $actor);
        $occurred = now()->subDay();
        $this->actingAs($actor)->post("/shipments/{$shipment->id}/transitions", [
            'status' => 'booked',
            'occurred_at' => $occurred->copy()->setTimezone('Asia/Dubai')->toIso8601String(),
        ])->assertSessionHasNoErrors();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->id);
        try {
            $shipment->refresh();
            $event = $shipment->trackingEvents()->firstOrFail();
            self::assertTrue($event->occurred_at->equalTo($occurred));
            self::assertTrue($shipment->last_status_at->equalTo($occurred));
            self::assertTrue($shipment->booked_at->equalTo($occurred));
            self::assertTrue($event->created_at->equalTo(now()));
        } finally {
            $context->forget();
        }
    }

    public function test_future_or_invalid_status_dates_are_rejected(): void
    {
        $company = $this->createCompany('DATE');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.update']);
        $shipment = $this->draftShipment($company, $branch, $actor);
        foreach (['not-a-date', now()->addDay()->toIso8601String()] as $date) {
            $this->actingAs($actor)->post("/shipments/{$shipment->id}/transitions", ['status' => 'booked', 'occurred_at' => $date])->assertSessionHasErrors('occurred_at');
        }
        self::assertSame('draft', Shipment::withoutGlobalScopes()->findOrFail($shipment->id)->status);
    }

    public function test_a_valid_transition_updates_the_shipment_and_writes_an_immutable_tracking_event(): void
    {
        $company = $this->createCompany('STA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.update']);
        $shipment = $this->bookedShipment($company, $branch, $actor);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/transitions", [
                'status' => 'received',
                'location' => 'Dubai hub',
                'description' => 'Received from consignor.',
            ])
            ->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $shipment->refresh();
            self::assertSame('received', $shipment->status);
            self::assertSame('Dubai hub', $shipment->last_location);

            $event = $shipment->trackingEvents()->firstOrFail();
            self::assertSame('booked', $event->from_status);
            self::assertSame('received', $event->to_status);
            self::assertSame($actor->getKey(), $event->created_by);
        } finally {
            $context->forget();
        }
    }

    public function test_an_invalid_transition_is_rejected_and_the_shipment_is_unchanged(): void
    {
        $company = $this->createCompany('STB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.update']);
        $shipment = $this->draftShipment($company, $branch, $actor);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/transitions", ['status' => 'delivered'])
            ->assertSessionHasErrors('status');

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $shipment->refresh();
            self::assertSame('draft', $shipment->status);
            self::assertSame(0, $shipment->trackingEvents()->count());
        } finally {
            $context->forget();
        }
    }

    public function test_a_tracking_event_cannot_be_updated_or_deleted(): void
    {
        $company = $this->createCompany('STC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.update']);
        $shipment = $this->bookedShipment($company, $branch, $actor);

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/transitions", ['status' => 'received']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $event = $shipment->trackingEvents()->firstOrFail();

            $this->expectException(\LogicException::class);
            $event->update(['description' => 'tampered']);
        } finally {
            $context->forget();
        }
    }

    public function test_a_user_without_tracking_update_permission_cannot_transition_a_shipment(): void
    {
        $company = $this->createCompany('STD');
        $branch = $this->createBranch($company, 'DXB');
        $manager = $this->createUser($company, $branch);
        $this->grantPermissions($manager, ['shipments.view', 'shipments.manage', 'tracking.update']);
        $shipment = $this->draftShipment($company, $branch, $manager);

        $viewer = $this->createUser($company, $branch);
        $this->grantPermissions($viewer, ['shipments.view']);

        $this->actingAs($viewer)
            ->post("/shipments/{$shipment->getKey()}/transitions", ['status' => 'booked'])
            ->assertForbidden();
    }

    private function draftShipment(Company $company, Branch $branch, User $actor): Shipment
    {
        $this->actingAs($actor)->post('/shipments', [
            'branch_id' => $branch->getKey(),
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [['weight_kg' => 5]],
        ]);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            return Shipment::query()->latest('id')->firstOrFail();
        } finally {
            $context->forget();
        }
    }

    private function bookedShipment(Company $company, Branch $branch, User $actor): Shipment
    {
        $shipment = $this->draftShipment($company, $branch, $actor);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/transitions", ['status' => 'booked']);

        return $shipment;
    }
}
