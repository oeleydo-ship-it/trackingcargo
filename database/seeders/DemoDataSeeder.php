<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CustomsClearanceStatus;
use App\Enums\DeliveryAssignmentStatus;
use App\Enums\DeliveryAttemptOutcome;
use App\Enums\InvoiceStatus;
use App\Enums\MasterStatus;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatusRole;
use App\Enums\WarehouseScanType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\RateCard;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\InvoiceTransitionService;
use App\Services\Billing\PaymentService;
use App\Services\Customs\CustomsClearanceService;
use App\Services\Customs\CustomsClearanceTransitionService;
use App\Services\Delivery\DeliveryAssignmentService;
use App\Services\Delivery\DeliveryAssignmentTransitionService;
use App\Services\Delivery\DeliveryAttemptService;
use App\Services\Freight\LoadUnitService;
use App\Services\Freight\ManifestService;
use App\Services\Freight\MasterService;
use App\Services\Freight\MasterTransitionService;
use App\Services\Shipments\ShipmentService;
use App\Services\Shipments\ShipmentTransitionService;
use App\Services\Warehouse\WarehouseScanService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Populates one company (GLX/Dubai) with realistic transactional data — a
 * handful of customers and shipments spread across the lifecycle — so the
 * dashboards, operations boards, and reports built in Phase 10 have
 * something to show right after a fresh `migrate --seed`, instead of every
 * widget rendering an empty state. PAC is deliberately left with only the
 * DatabaseSeeder skeleton, which is enough on its own to demonstrate that
 * GLX's demo data never leaks across the tenant boundary.
 *
 * Guarded by a `-DEMO-` customer_number prefix so re-running `db:seed`
 * against a database that already has this data does not pile up
 * duplicate shipments.
 */
final class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('code', 'GLX')->first();

        if ($company === null) {
            return;
        }

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        // Several domain services broadcast synchronously (ShouldBroadcastNow,
        // e.g. PackageScanned, DeliveryAttemptRecorded) so a scan or delivery
        // attempt can update a live dispatch board with no queue worker
        // involved. That means they need a reachable broadcast connection —
        // fine in a running app, but demo seeding must work on a fresh
        // `migrate --seed` before Reverb has ever been started.
        $originalBroadcaster = config('broadcasting.default');
        Config::set('broadcasting.default', 'log');

        try {
            if (Customer::query()->where('customer_number', 'like', 'GLX-DEMO-%')->exists()) {
                return;
            }

            $branch = Branch::query()->where('company_id', $company->getKey())->firstOrFail();
            $warehouse = Warehouse::query()->where('branch_id', $branch->getKey())->firstOrFail();
            $receivingLocation = $warehouse->zones()->where('code', 'RCV')->firstOrFail()->locations()->where('code', 'RCV-01')->firstOrFail();
            $actor = User::query()->where('email', 'glx@cargoflow.test')->firstOrFail();
            $driver = Driver::query()->where('branch_id', $branch->getKey())->firstOrFail();

            $customerA = Customer::query()->create([
                'branch_id' => $branch->getKey(),
                'customer_number' => 'GLX-DEMO-000001',
                'type' => 'business',
                'name' => 'Al Maha Trading LLC',
                'company_name' => 'Al Maha Trading LLC',
                'email' => 'ops@almaha-demo.test',
                'phone' => '+971-4-555-0101',
            ]);

            $customerB = Customer::query()->create([
                'branch_id' => $branch->getKey(),
                'customer_number' => 'GLX-DEMO-000002',
                'type' => 'individual',
                'name' => 'Fatima Al Suwaidi',
                'email' => 'fatima.suwaidi@demo.test',
                'phone' => '+971-50-555-0102',
            ]);

            RateCard::query()->create([
                'branch_id' => $branch->getKey(),
                'name' => 'Standard Air — GCC to Philippines',
                'mode' => 'air',
                'currency' => 'AED',
                'base_fee' => 25,
                'min_charge' => 50,
                'is_active' => true,
            ])->tiers()->create(['min_weight_kg' => 0, 'max_weight_kg' => null, 'price_per_kg' => 18]);

            // Shipment 1: freshly booked, still sitting in draft — the earliest stage of the pipeline.
            $this->createShipment($actor, $branch, $customerA, 'Manila', 3.5, ['role' => 'consignor', 'name' => 'GulfLink Dubai Origin']);

            // Shipment 2: received into the warehouse, waiting to be consolidated onto a flight.
            $shipment2 = $this->createShipment($actor, $branch, $customerB, 'Cebu', 8, ['role' => 'consignor', 'name' => 'GulfLink Dubai Origin']);
            app(ShipmentTransitionService::class)->transitionToRole($shipment2, ShipmentStatusRole::Booked, $actor);
            $package2 = $shipment2->packages()->firstOrFail();
            app(WarehouseScanService::class)->scan($warehouse, $package2, WarehouseScanType::Receive, $actor, (string) Str::uuid(), $receivingLocation);
            app(ShipmentTransitionService::class)->transitionToRole($shipment2, ShipmentStatusRole::Received, $actor);

            // Shipment 3: consolidated onto a flight and awaiting customs clearance in Manila.
            $shipment3 = $this->createShipment($actor, $branch, $customerA, 'Manila', 12, ['role' => 'consignor', 'name' => 'GulfLink Dubai Origin']);
            app(ShipmentTransitionService::class)->transitionToRole($shipment3, ShipmentStatusRole::Booked, $actor);
            $package3 = $shipment3->packages()->firstOrFail();
            app(WarehouseScanService::class)->scan($warehouse, $package3, WarehouseScanType::Receive, $actor, (string) Str::uuid(), $receivingLocation);
            app(ShipmentTransitionService::class)->transitionToRole($shipment3, ShipmentStatusRole::Received, $actor);

            $master = app(MasterService::class)->create([
                'branch_id' => $branch->getKey(), 'mode' => 'air', 'carrier_code' => 'EK',
                'flight_number' => 'EK332', 'origin_airport' => 'DXB', 'destination_airport' => 'MNL',
            ], $actor);
            $unit = app(LoadUnitService::class)->create($master, ['type' => 'pallet', 'unit_number' => 'ULD-GLX-DEMO-01'], $actor);
            // WarehouseScanService::scan() caches $package's warehouseLocation
            // relation as null on the earlier Receive call; a refresh here
            // forces it to re-resolve against the FK the Receive scan just
            // set, the same way a fresh per-request model would.
            $package3->refresh();
            app(WarehouseScanService::class)->scan($warehouse, $package3, WarehouseScanType::Load, $actor, (string) Str::uuid(), loadUnit: $unit);
            app(MasterTransitionService::class)->transition($master, MasterStatus::Closed, $actor);
            app(ManifestService::class)->generate($master, $actor);
            app(ShipmentTransitionService::class)->transitionToRole($shipment3, ShipmentStatusRole::InTransit, $actor);
            app(CustomsClearanceService::class)->open($shipment3, ['declaration_number' => 'DXB-DEC-DEMO-01', 'customs_office' => 'Manila Customs'], $actor);

            // Shipment 4: the full reference lifecycle — delivered and paid in full.
            $shipment4 = $this->createShipment($actor, $branch, $customerB, 'Cebu', 5, ['role' => 'consignor', 'name' => 'GulfLink Dubai Origin']);
            app(ShipmentTransitionService::class)->transitionToRole($shipment4, ShipmentStatusRole::Booked, $actor);
            $package4 = $shipment4->packages()->firstOrFail();
            app(WarehouseScanService::class)->scan($warehouse, $package4, WarehouseScanType::Receive, $actor, (string) Str::uuid(), $receivingLocation);
            app(ShipmentTransitionService::class)->transitionToRole($shipment4, ShipmentStatusRole::Received, $actor);
            app(ShipmentTransitionService::class)->transitionToRole($shipment4, ShipmentStatusRole::InTransit, $actor);
            $clearance4 = app(CustomsClearanceService::class)->open($shipment4, ['declaration_number' => 'DXB-DEC-DEMO-02', 'customs_office' => 'Cebu Customs'], $actor);
            app(CustomsClearanceTransitionService::class)->transition($clearance4, CustomsClearanceStatus::UnderReview, $actor, (string) Str::uuid());
            app(CustomsClearanceTransitionService::class)->transition($clearance4, CustomsClearanceStatus::Cleared, $actor, (string) Str::uuid());
            $assignment4 = app(DeliveryAssignmentService::class)->assign($shipment4, ['driver_id' => $driver->getKey()], $actor);
            app(DeliveryAssignmentTransitionService::class)->transition($assignment4, DeliveryAssignmentStatus::OutForDelivery, $actor);

            $signature = UploadedFile::fake()->image('signature.png');
            app(DeliveryAttemptService::class)->record($assignment4, DeliveryAttemptOutcome::Succeeded, $actor, (string) Str::uuid(), ['recipient_name' => $customerB->name], $signature);

            $invoice4 = app(InvoiceService::class)->create($customerB, ['currency' => 'AED'], $actor);
            app(InvoiceService::class)->addManualItem($invoice4, [
                'shipment_id' => $shipment4->getKey(),
                'description' => "Air freight — {$shipment4->tracking_number}",
                'quantity' => 1,
                'unit_price' => 180,
            ], $actor);
            app(InvoiceTransitionService::class)->transition($invoice4, InvoiceStatus::Issued, $actor);
            app(PaymentService::class)->record($invoice4, 180.0, PaymentMethod::BankTransfer, $actor, (string) Str::uuid());
        } finally {
            Config::set('broadcasting.default', $originalBroadcaster);
            $context->forget();
        }
    }

    /** @param array{role: string, name: string} $consignor */
    private function createShipment(User $actor, Branch $branch, Customer $customer, string $destinationCity, float $weightKg, array $consignor): Shipment
    {
        return app(ShipmentService::class)->create(
            [
                'branch_id' => $branch->getKey(),
                'customer_id' => $customer->getKey(),
                'mode' => 'air',
                'carrier_code' => 'mock',
                'origin_country_code' => 'AE',
                'destination_country_code' => 'PH',
                'destination_city' => $destinationCity,
                'currency' => 'AED',
            ],
            [
                $consignor,
                [
                    'role' => 'consignee',
                    'customer_id' => $customer->getKey(),
                    'name' => $customer->name,
                    'address' => [
                        'line1' => '24 Mabini Street',
                        'city' => $destinationCity,
                        'country_code' => 'PH',
                    ],
                ],
            ],
            [['weight_kg' => $weightKg]],
            $actor,
        );
    }
}
