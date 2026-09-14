<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoadUnit;
use App\Models\Master;
use App\Models\PackageScan;
use App\Models\ShipmentPackage;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class WarehouseScanTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_receiving_a_package_assigns_it_to_a_location_and_reconciles_the_count(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WSA', ['warehouses.view', 'warehouses.manage', 'packages.scan', 'warehouse.receive', 'shipments.view']);
        $warehouse = $this->createWarehouse($company, $branch, $actor);
        $location = $this->createLocation($company, $warehouse, $actor, 'RCV-01');
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());

        $this->actingAs($actor)
            ->post("/warehouses/{$warehouse->getKey()}/scans", [
                'idempotency_key' => (string) Str::uuid(),
                'scan_type' => 'receive',
                'package_barcode' => $package->barcode,
                'to_location_code' => $location->code,
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($package, $location): void {
            $package->refresh();
            $location->refresh();
            self::assertSame($location->getKey(), $package->warehouse_location_id);
            self::assertSame(1, $location->package_count);
        });
    }

    public function test_retrying_the_same_idempotency_key_does_not_duplicate_the_scan_or_double_count(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WSB', ['warehouses.view', 'warehouses.manage', 'packages.scan', 'warehouse.receive', 'shipments.view']);
        $warehouse = $this->createWarehouse($company, $branch, $actor);
        $location = $this->createLocation($company, $warehouse, $actor, 'RCV-01');
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());
        $key = (string) Str::uuid();

        $payload = [
            'idempotency_key' => $key,
            'scan_type' => 'receive',
            'package_barcode' => $package->barcode,
            'to_location_code' => $location->code,
        ];

        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/scans", $payload)->assertRedirect();
        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/scans", $payload)->assertRedirect();

        $this->withTenant($company, function () use ($location, $key): void {
            $location->refresh();
            self::assertSame(1, $location->package_count);
            self::assertSame(1, PackageScan::query()->where('idempotency_key', $key)->count());
        });
    }

    public function test_a_package_must_be_received_before_it_can_be_sorted(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WSC', ['warehouses.view', 'warehouses.manage', 'packages.scan', 'shipments.view']);
        $warehouse = $this->createWarehouse($company, $branch, $actor);
        $location = $this->createLocation($company, $warehouse, $actor, 'STO-01');
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());

        $this->actingAs($actor)
            ->post("/warehouses/{$warehouse->getKey()}/scans", [
                'idempotency_key' => (string) Str::uuid(),
                'scan_type' => 'sort',
                'package_barcode' => $package->barcode,
                'to_location_code' => $location->code,
            ])
            ->assertSessionHasErrors('package');
    }

    public function test_sorting_moves_a_package_between_locations_and_reconciles_both_counts(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WSD', ['warehouses.view', 'warehouses.manage', 'packages.scan', 'warehouse.receive', 'shipments.view']);
        $warehouse = $this->createWarehouse($company, $branch, $actor);
        $from = $this->createLocation($company, $warehouse, $actor, 'RCV-01');
        $to = $this->createLocation($company, $warehouse, $actor, 'STO-01');
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());

        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/scans", [
            'idempotency_key' => (string) Str::uuid(), 'scan_type' => 'receive',
            'package_barcode' => $package->barcode, 'to_location_code' => $from->code,
        ])->assertRedirect();

        $this->actingAs($actor)
            ->post("/warehouses/{$warehouse->getKey()}/scans", [
                'idempotency_key' => (string) Str::uuid(), 'scan_type' => 'sort',
                'package_barcode' => $package->barcode, 'to_location_code' => $to->code,
            ])->assertRedirect();

        $this->withTenant($company, function () use ($from, $to, $package): void {
            $from->refresh();
            $to->refresh();
            $package->refresh();
            self::assertSame(0, $from->package_count);
            self::assertSame(1, $to->package_count);
            self::assertSame($to->getKey(), $package->warehouse_location_id);
        });
    }

    public function test_loading_a_scanned_package_clears_its_warehouse_location_and_reconciles_the_load_unit(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WSE', ['warehouses.view', 'warehouses.manage', 'packages.scan', 'warehouse.receive', 'shipments.view', 'shipments.manage', 'flights.view', 'flights.manage', 'containers.view', 'containers.manage']);
        $warehouse = $this->createWarehouse($company, $branch, $actor);
        $location = $this->createLocation($company, $warehouse, $actor, 'RCV-01');
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());
        $unit = $this->createLoadUnit($company, $branch, $actor);

        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/scans", [
            'idempotency_key' => (string) Str::uuid(), 'scan_type' => 'receive',
            'package_barcode' => $package->barcode, 'to_location_code' => $location->code,
        ])->assertRedirect();

        $this->actingAs($actor)
            ->post("/warehouses/{$warehouse->getKey()}/scans", [
                'idempotency_key' => (string) Str::uuid(), 'scan_type' => 'load',
                'package_barcode' => $package->barcode, 'load_unit_id' => $unit->getKey(),
            ])->assertRedirect();

        $this->withTenant($company, function () use ($location, $package, $unit): void {
            $location->refresh();
            $package->refresh();
            $unit->refresh();
            self::assertNull($package->warehouse_location_id);
            self::assertSame($unit->getKey(), $package->load_unit_id);
            self::assertSame(0, $location->package_count);
            self::assertSame(1, $unit->package_count);
        });
    }

    public function test_a_user_without_receive_permission_cannot_receive_even_with_base_scan_permission(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WSF', ['warehouses.view', 'warehouses.manage', 'packages.scan', 'shipments.view']);
        $warehouse = $this->createWarehouse($company, $branch, $actor);
        $location = $this->createLocation($company, $warehouse, $actor, 'RCV-01');
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());

        $this->actingAs($actor)
            ->post("/warehouses/{$warehouse->getKey()}/scans", [
                'idempotency_key' => (string) Str::uuid(),
                'scan_type' => 'receive',
                'package_barcode' => $package->barcode,
                'to_location_code' => $location->code,
            ])
            ->assertForbidden();
    }

    public function test_dispatch_requires_the_package_to_be_at_a_warehouse_location(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WSG', ['warehouses.view', 'warehouses.manage', 'packages.scan', 'warehouse.dispatch', 'shipments.view']);
        $warehouse = $this->createWarehouse($company, $branch, $actor);
        $shipment = $this->createShipment($company, $branch);
        $package = $this->packageFor($company, $shipment->getKey());

        $this->actingAs($actor)
            ->post("/warehouses/{$warehouse->getKey()}/scans", [
                'idempotency_key' => (string) Str::uuid(),
                'scan_type' => 'dispatch',
                'package_barcode' => $package->barcode,
            ])
            ->assertSessionHasErrors('package');
    }

    /**
     * Phase 10 security-review fix: WarehousePolicy::scan() previously only
     * checked company_id, unlike WarehousePolicy::view() which already
     * branch-scoped the same model — a branch-scoped packages.scan user
     * could receive/dispatch/sort at a warehouse belonging to a different
     * branch of the same company, despite being unable to even view that
     * warehouse's detail page.
     */
    public function test_a_branch_scoped_user_cannot_scan_at_another_branchs_warehouse(): void
    {
        $company = $this->createCompany('WSH');
        $branchA = $this->createBranch($company, 'DXB');
        $branchB = $this->createBranch($company, 'AUH');
        $manager = $this->createUser($company);
        $this->grantPermissions($manager, ['warehouses.view', 'warehouses.manage', 'packages.scan', 'warehouse.receive', 'shipments.view']);
        $branchStaff = $this->createUser($company, $branchA);
        $this->grantPermissions($branchStaff, ['warehouses.view', 'packages.scan', 'warehouse.receive', 'shipments.view']);

        $warehouseB = $this->createWarehouse($company, $branchB, $manager);
        $location = $this->createLocation($company, $warehouseB, $manager, 'RCV-01');
        $shipment = $this->createShipment($company, $branchB);
        $package = $this->packageFor($company, $shipment->getKey());

        $this->actingAs($branchStaff)
            ->post("/warehouses/{$warehouseB->getKey()}/scans", [
                'idempotency_key' => (string) Str::uuid(),
                'scan_type' => 'receive',
                'package_barcode' => $package->barcode,
                'to_location_code' => $location->code,
            ])
            ->assertForbidden();

        // The warehouse-manager (warehouses.manage) is not branch-restricted.
        $this->actingAs($manager)
            ->post("/warehouses/{$warehouseB->getKey()}/scans", [
                'idempotency_key' => (string) Str::uuid(),
                'scan_type' => 'receive',
                'package_barcode' => $package->barcode,
                'to_location_code' => $location->code,
            ])
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

    private function createWarehouse(Company $company, Branch $branch, User $actor): Warehouse
    {
        $this->actingAs($actor)->post('/warehouses', ['code' => 'WH-'.$company->code, 'name' => 'Warehouse', 'branch_id' => $branch->getKey()]);

        $warehouse = null;
        $this->withTenant($company, function () use ($company, &$warehouse): void {
            $warehouse = Warehouse::query()->where('code', 'WH-'.$company->code)->firstOrFail();
        });

        return $warehouse;
    }

    private function createLocation(Company $company, Warehouse $warehouse, User $actor, string $code): WarehouseLocation
    {
        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/zones", ['code' => 'Z-'.$code, 'name' => 'Zone', 'type' => 'storage']);

        $zone = null;
        $this->withTenant($company, function () use ($warehouse, &$zone): void {
            $zone = $warehouse->zones()->latest('id')->firstOrFail();
        });

        $this->actingAs($actor)->post("/warehouses/{$warehouse->getKey()}/zones/{$zone->getKey()}/locations", ['code' => $code]);

        $location = null;
        $this->withTenant($company, function () use ($zone, &$location): void {
            $location = $zone->locations()->latest('id')->firstOrFail();
        });

        return $location;
    }

    private function createLoadUnit(Company $company, Branch $branch, User $actor): LoadUnit
    {
        $this->actingAs($actor)->post('/freight/masters', [
            'branch_id' => $branch->getKey(), 'mode' => 'air', 'carrier_code' => 'EK', 'flight_number' => 'EK332',
            'origin_airport' => 'DXB', 'destination_airport' => 'MNL',
        ]);

        $master = null;
        $this->withTenant($company, function () use (&$master): void {
            $master = Master::query()->latest('id')->firstOrFail();
        });

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units", ['type' => 'pallet', 'unit_number' => 'ULD-'.$company->code]);

        $unit = null;
        $this->withTenant($company, function () use ($master, &$unit): void {
            $unit = LoadUnit::query()->where('master_id', $master->getKey())->latest('id')->firstOrFail();
        });

        return $unit;
    }

    private function packageFor(Company $company, int $shipmentId): ShipmentPackage
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return ShipmentPackage::query()->where('shipment_id', $shipmentId)->firstOrFail();
        } finally {
            $context->forget();
        }
    }
}
