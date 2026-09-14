<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryAttempt;
use App\Models\Driver;
use App\Models\Shipment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class DeliveryAttemptTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_successful_attempt_requires_a_signature_and_atomically_completes_the_shipment(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor, $assignment] = $this->setUpOutForDeliveryAssignment('DTA');

        $this->actingAs($actor)
            ->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", [
                'idempotency_key' => (string) Str::uuid(),
                'outcome' => 'succeeded',
                'recipient_name' => 'Jane Doe',
            ])
            ->assertSessionHasErrors('signature');

        $this->actingAs($actor)
            ->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", [
                'idempotency_key' => (string) Str::uuid(),
                'outcome' => 'succeeded',
                'recipient_name' => 'Jane Doe',
                'signature' => UploadedFile::fake()->image('signature.png'),
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($assignment): void {
            $assignment->refresh();
            self::assertSame('delivered', $assignment->status->value);
            self::assertNotNull($assignment->delivered_at);

            $shipment = Shipment::query()->findOrFail($assignment->shipment_id);
            self::assertSame('delivered', $shipment->status);

            $attempt = DeliveryAttempt::query()->where('delivery_assignment_id', $assignment->getKey())->firstOrFail();
            self::assertSame(1, $attempt->documents()->count());
        });
    }

    public function test_retrying_the_same_idempotency_key_does_not_duplicate_the_attempt_or_recomplete_the_shipment(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor, $assignment] = $this->setUpOutForDeliveryAssignment('DTB');
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'outcome' => 'succeeded',
            'recipient_name' => 'Jane Doe',
            'signature' => UploadedFile::fake()->image('signature.png'),
        ];

        $this->actingAs($actor)->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", $payload)->assertRedirect();
        $this->actingAs($actor)->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", $payload)->assertRedirect();

        $this->withTenant($company, function () use ($key): void {
            self::assertSame(1, DeliveryAttempt::query()->where('idempotency_key', $key)->count());
        });
    }

    public function test_a_failed_attempt_does_not_complete_the_assignment_and_allows_a_further_attempt(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor, $assignment] = $this->setUpOutForDeliveryAssignment('DTC');

        $this->actingAs($actor)
            ->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", [
                'idempotency_key' => (string) Str::uuid(),
                'outcome' => 'failed',
                'failure_reason' => 'Recipient not home',
                'reschedule_date' => now()->addDay()->toDateString(),
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($assignment): void {
            $assignment->refresh();
            self::assertSame('out_for_delivery', $assignment->status->value);
        });

        $this->actingAs($actor)
            ->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", [
                'idempotency_key' => (string) Str::uuid(),
                'outcome' => 'succeeded',
                'recipient_name' => 'Jane Doe',
                'signature' => UploadedFile::fake()->image('signature.png'),
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($assignment): void {
            self::assertSame(2, DeliveryAttempt::query()->where('delivery_assignment_id', $assignment->getKey())->count());
        });
    }

    public function test_an_attempt_cannot_be_recorded_before_the_assignment_is_out_for_delivery(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('DTD', ['deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $driver = $this->createDriver($company, $branch, $actor);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments", ['driver_id' => $driver->getKey()]);
        $assignment = $this->withTenantReturn($company, fn () => DeliveryAssignment::query()->where('shipment_id', $shipment->getKey())->firstOrFail());

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/attempts", [
                'idempotency_key' => (string) Str::uuid(),
                'outcome' => 'failed',
                'failure_reason' => 'Too early',
            ])
            ->assertSessionHasErrors('assignment');
    }

    /** @return array{0: Company, 1: Branch, 2: User, 3: DeliveryAssignment} */
    private function setUpOutForDeliveryAssignment(string $code): array
    {
        [$company, $branch, $actor] = $this->setUpTenant($code, ['deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage', 'shipments.view', 'tracking.update']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $driver = $this->createDriver($company, $branch, $actor);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments", ['driver_id' => $driver->getKey()]);
        $assignment = $this->withTenantReturn($company, fn () => DeliveryAssignment::query()->where('shipment_id', $shipment->getKey())->firstOrFail());
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/transitions", ['status' => 'out_for_delivery']);

        return [$company, $branch, $actor, $assignment];
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

        return $this->withTenantReturn($company, fn () => Driver::query()->where('user_id', $driverUser->getKey())->firstOrFail());
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
