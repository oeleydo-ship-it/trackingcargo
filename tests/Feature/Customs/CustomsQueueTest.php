<?php

declare(strict_types=1);

namespace Tests\Feature\Customs;

use App\Models\Company;
use App\Models\CustomsClearance;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomsQueueTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_queue_excludes_cleared_and_rejected_clearances(): void
    {
        $company = $this->createCompany('CQA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['customs.view', 'shipments.view']);

        $shipmentPending = $this->createShipment($company, $branch, ['status' => 'at_customs']);
        $shipmentCleared = $this->createShipment($company, $branch, ['status' => 'at_customs']);

        $this->withTenant($company, function () use ($branch, $shipmentPending, $shipmentCleared): void {
            CustomsClearance::query()->create(['branch_id' => $branch->getKey(), 'shipment_id' => $shipmentPending->getKey(), 'status' => 'pending']);
            CustomsClearance::query()->create(['branch_id' => $branch->getKey(), 'shipment_id' => $shipmentCleared->getKey(), 'status' => 'cleared']);
        });

        $response = $this->actingAs($actor)->get('/customs/queue');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('clearances', 1)
            ->where('clearances.0.shipment_id', $shipmentPending->getKey()));
    }

    public function test_a_branch_scoped_user_only_sees_their_branchs_queue(): void
    {
        $company = $this->createCompany('CQB');
        $branchA = $this->createBranch($company, 'DXB');
        $branchB = $this->createBranch($company, 'AUH');
        $staffA = $this->createUser($company, $branchA);
        $this->grantPermissions($staffA, ['customs.view', 'shipments.view']);
        $manager = $this->createUser($company);
        $this->grantPermissions($manager, ['customs.view', 'customs.manage', 'shipments.view']);

        $shipmentA = $this->createShipment($company, $branchA, ['status' => 'at_customs']);
        $shipmentB = $this->createShipment($company, $branchB, ['status' => 'at_customs']);

        $this->withTenant($company, function () use ($branchA, $branchB, $shipmentA, $shipmentB): void {
            CustomsClearance::query()->create(['branch_id' => $branchA->getKey(), 'shipment_id' => $shipmentA->getKey(), 'status' => 'pending']);
            CustomsClearance::query()->create(['branch_id' => $branchB->getKey(), 'shipment_id' => $shipmentB->getKey(), 'status' => 'pending']);
        });

        $this->actingAs($staffA)->get('/customs/queue')->assertInertia(fn ($page) => $page->has('clearances', 1));
        $this->actingAs($manager)->get('/customs/queue')->assertInertia(fn ($page) => $page->has('clearances', 2));
    }

    public function test_a_user_without_customs_permission_cannot_view_the_queue(): void
    {
        $company = $this->createCompany('CQC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)->get('/customs/queue')->assertForbidden();
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
}
