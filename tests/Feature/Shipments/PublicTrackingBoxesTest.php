<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Models\Box;
use App\Models\BoxSize;
use App\Models\Company;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class PublicTrackingBoxesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function asTenant(Company $company, Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }

    public function test_public_tracking_shows_how_many_boxes_of_each_kind(): void
    {
        $company = $this->createCompany('PBA');
        $branch = $this->createBranch($company, 'DXB');
        $shipment = $this->createShipment($company, $branch, ['tracking_number' => 'PBA-DXB-10000001', 'package_count' => 4]);

        $this->asTenant($company, function () use ($shipment): void {
            $box = Box::query()->create(['name' => 'Box', 'is_active' => true]);
            $jumbo = BoxSize::query()->create(['box_id' => $box->getKey(), 'name' => 'Jumbo', 'length_cm' => 60, 'width_cm' => 40, 'height_cm' => 40, 'is_active' => true]);

            $shipment->packages()->update(['box_size_id' => $jumbo->getKey(), 'pieces' => 3]);
            $shipment->packages()->create(['package_number' => 2, 'barcode' => 'PBA-2', 'weight_kg' => 1, 'pieces' => 1]);
        });

        $this->get('/track/PBA-DXB-10000001')->assertInertia(fn ($page) => $page
            ->where('shipment.boxes', [
                ['label' => 'Box · Jumbo', 'count' => 3],
                ['label' => 'Custom', 'count' => 1],
            ]));
    }

    public function test_a_box_size_deleted_after_booking_still_shows(): void
    {
        $company = $this->createCompany('PBB');
        $branch = $this->createBranch($company, 'DXB');
        $shipment = $this->createShipment($company, $branch, ['tracking_number' => 'PBB-DXB-10000001']);

        $this->asTenant($company, function () use ($shipment): void {
            $box = Box::query()->create(['name' => 'Box', 'is_active' => true]);
            $small = BoxSize::query()->create(['box_id' => $box->getKey(), 'name' => 'Small', 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'is_active' => true]);
            $shipment->packages()->update(['box_size_id' => $small->getKey()]);
            $small->delete();
            $box->delete();
        });

        $this->get('/track/PBB-DXB-10000001')->assertInertia(fn ($page) => $page
            ->where('shipment.boxes', [['label' => 'Box · Small', 'count' => 1]]));
    }

    public function test_public_tracking_does_not_expose_package_weights_or_values(): void
    {
        $company = $this->createCompany('PBC');
        $branch = $this->createBranch($company, 'DXB');
        $this->createShipment($company, $branch, ['tracking_number' => 'PBC-DXB-10000001']);

        $body = $this->get('/track/PBC-DXB-10000001')->getContent();

        $this->assertStringNotContainsString('weight_kg', $body);
        $this->assertStringNotContainsString('barcode', $body);
    }
}
