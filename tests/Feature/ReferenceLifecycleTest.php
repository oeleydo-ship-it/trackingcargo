<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use App\Models\LoadUnit;
use App\Models\Manifest;
use App\Models\Master;
use App\Models\Shipment;
use App\Models\ShipmentPackage;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The Phase 10 acceptance criterion "reference lifecycle passes end-to-end":
 * one shipment walks through booking, warehouse receiving, air-freight
 * consolidation, customs clearance, last-mile delivery, and billing —
 * exercising every domain service through its real HTTP route, the same way
 * a genuine booking would move through the system.
 */
final class ReferenceLifecycleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_shipment_moves_through_booking_warehouse_freight_customs_delivery_and_billing(): void
    {
        Storage::fake('local');

        $company = $this->createCompany('RLC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, [
            'shipments.view', 'shipments.manage', 'tracking.update',
            'warehouses.view', 'warehouses.manage', 'packages.scan', 'warehouse.receive', 'warehouse.dispatch',
            'flights.view', 'flights.manage', 'containers.view', 'containers.manage', 'manifests.view', 'manifests.manage',
            'customs.view', 'customs.manage',
            'deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage',
            'billing.view', 'billing.manage', 'payments.manage',
        ]);
        $customer = $this->createCustomer($company, 'Cebu Consignee', ['branch_id' => $branch->getKey()]);

        // 1. Booking: a shipment is created with a consignor and consignee party and one package.
        $this->actingAs($actor)->post('/shipments', [
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
            'mode' => 'air',
            'carrier_code' => 'mock',
            'origin_country_code' => 'AE',
            'destination_country_code' => 'PH',
            'destination_city' => 'Cebu',
            'currency' => 'AED',
            'parties' => [
                ['role' => 'consignor', 'name' => 'CargoFlow Dubai Origin'],
                ['role' => 'consignee', 'customer_id' => $customer->getKey(), 'name' => 'Cebu Consignee', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [
                ['weight_kg' => 5, 'length' => 30, 'width' => 20, 'height' => 15, 'dimension_unit' => 'cm'],
            ],
        ])->assertRedirect();

        $shipment = $this->withTenant($company, fn () => Shipment::query()->where('branch_id', $branch->getKey())->latest('id')->firstOrFail());
        self::assertSame('draft', $shipment->status);

        $this->transitionShipment($actor, $shipment, 'booked');

        // 2. Warehouse: the package is received at a physical location.
        $warehouse = $this->createWarehouse($company, $branch, $actor);
        $location = $this->createLocation($company, $warehouse, $actor, 'RCV-01');
        $package = $this->withTenant($company, fn () => ShipmentPackage::query()->where('shipment_id', $shipment->getKey())->firstOrFail());

        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/scans", [
            'idempotency_key' => (string) Str::uuid(),
            'scan_type' => 'receive',
            'package_barcode' => $package->barcode,
            'to_location_code' => $location->code,
        ])->assertRedirect();

        $this->transitionShipment($actor, $shipment, 'received');

        // 3. Freight: an air master is opened, a load unit is built, and the package is loaded onto it.
        $this->actingAs($actor)->post('/freight/masters', [
            'branch_id' => $branch->getKey(),
            'mode' => 'air',
            'carrier_code' => 'EK',
            'flight_number' => 'EK332',
            'origin_airport' => 'DXB',
            'destination_airport' => 'MNL',
        ])->assertRedirect();
        $master = $this->withTenant($company, fn () => Master::query()->where('branch_id', $branch->getKey())->latest('id')->firstOrFail());

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units", [
            'type' => 'pallet',
            'unit_number' => 'ULD-RLC-01',
        ])->assertRedirect();
        $unit = $this->withTenant($company, fn () => LoadUnit::query()->where('master_id', $master->getKey())->latest('id')->firstOrFail());

        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/scans", [
            'idempotency_key' => (string) Str::uuid(),
            'scan_type' => 'load',
            'package_barcode' => $package->barcode,
            'load_unit_id' => $unit->getKey(),
        ])->assertRedirect();

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/transitions", ['status' => 'closed'])->assertRedirect();
        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/manifests")->assertRedirect();

        $manifest = $this->withTenant($company, fn () => Manifest::query()->where('master_id', $master->getKey())->firstOrFail());
        self::assertNotNull($manifest->manifest_number);

        $this->transitionShipment($actor, $shipment, 'in_transit');

        // 4. Customs: the shipment clears customs and returns to transit.
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances", [
            'declaration_number' => 'DXB-DEC-0001',
            'customs_office' => 'Dubai Customs',
        ])->assertRedirect();
        $clearance = $this->withTenant($company, fn () => $shipment->customsClearances()->latest('id')->firstOrFail());

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", [
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'under_review',
        ])->assertRedirect();

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances/{$clearance->getKey()}/transitions", [
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'cleared',
        ])->assertRedirect();

        $this->withTenant($company, function () use ($shipment, $clearance): void {
            $clearance->refresh();
            self::assertSame('cleared', $clearance->status->value);
            $shipment->refresh();
            self::assertSame('in_transit', $shipment->status);
        });

        // 5. Delivery: a driver is assigned, dispatched, and completes a successful attempt.
        $driverUser = $this->createUser($company, $branch);
        $this->grantPermissions($driverUser, ['deliveries.view', 'deliveries.execute']);
        $this->actingAs($actor)->post('/drivers', ['user_id' => $driverUser->getKey(), 'branch_id' => $branch->getKey()])->assertRedirect();
        $driver = $this->withTenant($company, fn () => Driver::query()->where('user_id', $driverUser->getKey())->firstOrFail());

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments", [
            'driver_id' => $driver->getKey(),
        ])->assertRedirect();
        $assignment = $this->withTenant($company, fn () => $shipment->deliveryAssignments()->latest('id')->firstOrFail());

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/transitions", [
            'status' => 'out_for_delivery',
        ])->assertRedirect();

        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/delivery-assignments/{$assignment->getKey()}/attempts", [
            'idempotency_key' => (string) Str::uuid(),
            'outcome' => 'succeeded',
            'recipient_name' => 'Cebu Consignee',
            'signature' => UploadedFile::fake()->image('signature.png'),
        ])->assertRedirect();

        $this->withTenant($company, function () use ($shipment, $assignment): void {
            $assignment->refresh();
            self::assertSame('delivered', $assignment->status->value);
            $shipment->refresh();
            self::assertSame('delivered', $shipment->status);
        });

        // 6. Billing: an invoice is raised, issued, and paid in full.
        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices", [
            'currency' => 'AED',
        ])->assertRedirect();
        $invoice = $this->withTenant($company, fn () => $customer->invoices()->latest('id')->firstOrFail());

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/items", [
            'from_shipment' => false,
            'description' => 'Air freight — Dubai to Cebu',
            'quantity' => 1,
            'unit_price' => 250,
        ])->assertRedirect();

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/transitions", [
            'status' => 'issued',
        ])->assertRedirect();

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/payments", [
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 250,
            'method' => 'bank_transfer',
        ])->assertRedirect();

        $this->withTenant($company, function () use ($invoice): void {
            $invoice->refresh();
            self::assertSame('paid', $invoice->status->value);
            self::assertSame('0.00', number_format((float) $invoice->balance_due, 2, '.', ''));
            self::assertSame('250.00', number_format((float) $invoice->amount_paid, 2, '.', ''));
        });

        // The shipment's own tracking timeline reflects every transition made along the way,
        // including the ones triggered indirectly by the customs/delivery services.
        $this->withTenant($company, function () use ($shipment): void {
            $statuses = $shipment->trackingEvents()->reorder('id')->pluck('to_status')->all();
            self::assertSame(
                ['booked', 'received', 'in_transit', 'at_customs', 'in_transit', 'out_for_delivery', 'delivered'],
                $statuses,
            );
        });
    }

    private function transitionShipment(User $actor, Shipment $shipment, string $status): void
    {
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/transitions", ['status' => $status])->assertRedirect();
    }

    private function withTenant(Company $company, Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }

    private function createWarehouse(Company $company, Branch $branch, User $actor): Warehouse
    {
        $this->actingAs($actor)->post('/warehouses', ['code' => 'WH-RLC', 'name' => 'Dubai Warehouse', 'branch_id' => $branch->getKey()]);

        return $this->withTenant($company, fn () => Warehouse::query()->where('code', 'WH-RLC')->firstOrFail());
    }

    private function createLocation(Company $company, Warehouse $warehouse, User $actor, string $code): WarehouseLocation
    {
        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/zones", ['code' => 'Z-'.$code, 'name' => 'Zone', 'type' => 'receiving']);
        $zone = $this->withTenant($company, fn () => $warehouse->zones()->latest('id')->firstOrFail());

        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/zones/{$zone->getKey()}/locations", ['code' => $code]);

        return $this->withTenant($company, fn () => $zone->locations()->latest('id')->firstOrFail());
    }
}
