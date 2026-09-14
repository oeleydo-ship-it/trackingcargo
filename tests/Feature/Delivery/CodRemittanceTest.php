<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Models\CodRemittance;
use App\Models\Company;
use App\Models\DeliveryAssignment;
use App\Models\Driver;
use App\Models\User;
use App\Services\Delivery\CodRemittanceService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CodRemittanceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_successful_delivery_attempt_records_the_collected_amount(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor, $driverUser, $assignment] = $this->setUpOutForDeliveryAssignment('CRA');

        $this->actingAs($actor)
            ->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", [
                'idempotency_key' => (string) Str::uuid(),
                'outcome' => 'succeeded',
                'recipient_name' => 'Jane Doe',
                'collected_amount' => 75,
                'signature' => UploadedFile::fake()->image('signature.png'),
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($assignment): void {
            $driver = Driver::query()->findOrFail($assignment->driver_id);
            $remittances = app(CodRemittanceService::class);
            self::assertEqualsWithDelta(75.0, $remittances->outstandingCod($driver), 0.001);
        });
    }

    public function test_a_driver_can_remit_their_own_collected_cod_and_the_outstanding_balance_drops(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor, $driverUser, $assignment] = $this->setUpOutForDeliveryAssignment('CRB');
        $this->actingAs($actor)->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", [
            'idempotency_key' => (string) Str::uuid(),
            'outcome' => 'succeeded',
            'recipient_name' => 'Jane Doe',
            'collected_amount' => 100,
            'signature' => UploadedFile::fake()->image('signature.png'),
        ]);
        $driver = $this->withTenantReturn($company, fn () => Driver::query()->where('user_id', $driverUser->getKey())->firstOrFail());

        $this->actingAs($driverUser)
            ->post('/cod-remittances', [
                'idempotency_key' => 'remit-b-1',
                'driver_id' => $driver->getKey(),
                'amount' => 40,
                'currency' => 'AED',
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($driver): void {
            $remittances = app(CodRemittanceService::class);
            self::assertEqualsWithDelta(60.0, $remittances->outstandingCod($driver), 0.001);
        });
    }

    public function test_a_driver_cannot_remit_on_behalf_of_another_driver(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor, $driverUserA, $assignment] = $this->setUpOutForDeliveryAssignment('CRC');
        $this->actingAs($actor)->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", [
            'idempotency_key' => (string) Str::uuid(),
            'outcome' => 'succeeded',
            'recipient_name' => 'Jane Doe',
            'collected_amount' => 100,
            'signature' => UploadedFile::fake()->image('signature.png'),
        ]);
        $driverA = $this->withTenantReturn($company, fn () => Driver::query()->where('user_id', $driverUserA->getKey())->firstOrFail());

        $driverUserB = $this->createUser($company, $branch);
        $this->grantPermissions($driverUserB, ['deliveries.view', 'deliveries.execute']);
        $this->actingAs($actor)->post('/drivers', ['user_id' => $driverUserB->getKey(), 'branch_id' => $branch->getKey()]);

        $this->actingAs($driverUserB)
            ->post('/cod-remittances', [
                'idempotency_key' => 'remit-c-1',
                'driver_id' => $driverA->getKey(),
                'amount' => 10,
                'currency' => 'AED',
            ])
            ->assertForbidden();
    }

    public function test_a_remittance_cannot_exceed_the_outstanding_collected_cod(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor, $driverUser, $assignment] = $this->setUpOutForDeliveryAssignment('CRD');
        $this->actingAs($actor)->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", [
            'idempotency_key' => (string) Str::uuid(),
            'outcome' => 'succeeded',
            'recipient_name' => 'Jane Doe',
            'collected_amount' => 50,
            'signature' => UploadedFile::fake()->image('signature.png'),
        ]);
        $driver = $this->withTenantReturn($company, fn () => Driver::query()->where('user_id', $driverUser->getKey())->firstOrFail());

        $this->actingAs($actor)
            ->post('/cod-remittances', [
                'idempotency_key' => 'remit-d-1',
                'driver_id' => $driver->getKey(),
                'amount' => 80,
                'currency' => 'AED',
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_retrying_the_same_idempotency_key_does_not_duplicate_the_remittance(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor, $driverUser, $assignment] = $this->setUpOutForDeliveryAssignment('CRE');
        $this->actingAs($actor)->post("/shipments/{$assignment->shipment_id}/delivery-assignments/{$assignment->getKey()}/attempts", [
            'idempotency_key' => (string) Str::uuid(),
            'outcome' => 'succeeded',
            'recipient_name' => 'Jane Doe',
            'collected_amount' => 50,
            'signature' => UploadedFile::fake()->image('signature.png'),
        ]);
        $driver = $this->withTenantReturn($company, fn () => Driver::query()->where('user_id', $driverUser->getKey())->firstOrFail());
        $payload = ['idempotency_key' => 'remit-e-1', 'driver_id' => $driver->getKey(), 'amount' => 20, 'currency' => 'AED'];

        $this->actingAs($actor)->post('/cod-remittances', $payload)->assertRedirect();
        $this->actingAs($actor)->post('/cod-remittances', $payload)->assertRedirect();

        $this->withTenant($company, function (): void {
            self::assertSame(1, CodRemittance::query()->where('idempotency_key', 'remit-e-1')->count());
        });
    }

    /** @return array{0: Company, 1: Branch, 2: User, 3: User, 4: DeliveryAssignment} */
    private function setUpOutForDeliveryAssignment(string $code): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage', 'shipments.view', 'tracking.update', 'billing.manage']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit', 'cod_amount' => 100]);

        $driverUser = $this->createUser($company, $branch);
        $this->grantPermissions($driverUser, ['deliveries.view', 'deliveries.execute']);
        $this->actingAs($actor)->post('/drivers', ['user_id' => $driverUser->getKey(), 'branch_id' => $branch->getKey()]);

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments", [
            'driver_id' => $this->withTenantReturn($company, fn () => Driver::query()->where('user_id', $driverUser->getKey())->firstOrFail())->getKey(),
        ]);
        $assignment = $this->withTenantReturn($company, fn () => DeliveryAssignment::query()->where('shipment_id', $shipment->getKey())->firstOrFail());
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/transitions", ['status' => 'out_for_delivery']);

        return [$company, $branch, $actor, $driverUser, $assignment];
    }

    private function withTenant(Company $company, Closure $callback): void
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $callback();
        } finally {
            $context->forget();
        }
    }

    private function withTenantReturn(Company $company, Closure $callback): mixed
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
