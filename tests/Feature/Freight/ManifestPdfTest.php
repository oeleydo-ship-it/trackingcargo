<?php

declare(strict_types=1);

namespace Tests\Feature\Freight;

use App\Models\LoadUnit;
use App\Models\Manifest;
use App\Models\Master;
use App\Models\ShipmentPackage;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class ManifestPdfTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_generated_manifest_can_be_downloaded_as_a_pdf(): void
    {
        $company = $this->createCompany('MPA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['flights.view', 'flights.manage', 'containers.view', 'containers.manage', 'shipments.view', 'shipments.manage', 'manifests.view', 'manifests.manage']);

        $this->actingAs($actor)->post('/freight/masters', [
            'branch_id' => $branch->getKey(), 'mode' => 'air', 'carrier_code' => 'EK', 'flight_number' => 'EK332',
            'origin_airport' => 'DXB', 'destination_airport' => 'MNL',
        ]);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        $master = Master::query()->latest('id')->firstOrFail();
        $context->forget();

        $shipment = $this->createShipment($company, $branch);

        $context->resolveCompany((int) $company->getKey());
        $package = ShipmentPackage::query()->where('shipment_id', $shipment->getKey())->firstOrFail();
        $context->forget();

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units", ['type' => 'container', 'unit_number' => 'CONT-0001']);

        $context->resolveCompany((int) $company->getKey());
        $unit = LoadUnit::query()->where('master_id', $master->getKey())->firstOrFail();
        $context->forget();

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/load-units/{$unit->getKey()}/packages", ['package_id' => $package->getKey()]);
        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/transitions", ['status' => 'closed']);
        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/manifests");

        $context->resolveCompany((int) $company->getKey());
        $manifest = Manifest::query()->where('master_id', $master->getKey())->firstOrFail();
        $context->forget();

        $response = $this->actingAs($actor)->get("/freight/masters/{$master->getKey()}/manifests/{$manifest->getKey()}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', $response->getContent());
    }
}
