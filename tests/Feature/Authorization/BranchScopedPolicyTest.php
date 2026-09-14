<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\RateCard;
use App\Models\Vehicle;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Phase 10 security-review fix: DriverPolicy, VehiclePolicy,
 * DeliveryZonePolicy, and RateCardPolicy's view() checks only compared
 * company_id, even though all four underlying models have a nullable
 * branch_id (company-wide vs branch-specific), unlike every other model
 * with the same shape (Customer, Invoice, Shipment, Warehouse,
 * CustomsClearance, DeliveryAssignment), which already use the three-way
 * branch-scope OR. None of these four currently have a dedicated `show()`
 * route (only index() + update(), confirmed during the review), so this
 * was not exploitable through any live endpoint today — these tests assert
 * the authorization rule directly against the policy so the correct
 * behavior is locked in before any future `show()` route is added, rather
 * than relying on the accident of no route existing yet.
 */
final class BranchScopedPolicyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_branch_scoped_user_cannot_view_another_branchs_driver_but_a_manager_can(): void
    {
        $company = $this->createCompany('APA');
        $branchA = $this->createBranch($company, 'DXB');
        $branchB = $this->createBranch($company, 'AUH');
        $staffA = $this->createUser($company, $branchA);
        $this->grantPermissions($staffA, ['drivers.view']);
        $manager = $this->createUser($company);
        $this->grantPermissions($manager, ['drivers.view', 'drivers.manage']);
        $driverUser = $this->createUser($company, $branchB);

        $this->withTenant($company, function () use ($branchB, $staffA, $manager, $driverUser): void {
            $driver = Driver::query()->create(['branch_id' => $branchB->getKey(), 'user_id' => $driverUser->getKey(), 'status' => 'active']);

            self::assertFalse($staffA->can('view', $driver));
            self::assertTrue($manager->can('view', $driver));
        });
    }

    public function test_a_branch_scoped_user_cannot_view_another_branchs_vehicle_but_a_manager_can(): void
    {
        $company = $this->createCompany('APB');
        $branchA = $this->createBranch($company, 'DXB');
        $branchB = $this->createBranch($company, 'AUH');
        $staffA = $this->createUser($company, $branchA);
        $this->grantPermissions($staffA, ['vehicles.view']);
        $manager = $this->createUser($company);
        $this->grantPermissions($manager, ['vehicles.view', 'vehicles.manage']);

        $this->withTenant($company, function () use ($branchB, $staffA, $manager): void {
            $vehicle = Vehicle::query()->create(['branch_id' => $branchB->getKey(), 'registration_number' => 'V-APB', 'type' => 'van', 'status' => 'active']);

            self::assertFalse($staffA->can('view', $vehicle));
            self::assertTrue($manager->can('view', $vehicle));
        });
    }

    public function test_a_branch_scoped_user_cannot_view_another_branchs_delivery_zone_but_a_manager_can(): void
    {
        $company = $this->createCompany('APC');
        $branchA = $this->createBranch($company, 'DXB');
        $branchB = $this->createBranch($company, 'AUH');
        $staffA = $this->createUser($company, $branchA);
        $this->grantPermissions($staffA, ['deliveries.view']);
        $manager = $this->createUser($company);
        $this->grantPermissions($manager, ['deliveries.view', 'deliveries.manage']);

        $this->withTenant($company, function () use ($branchB, $staffA, $manager): void {
            $zone = DeliveryZone::query()->create(['branch_id' => $branchB->getKey(), 'code' => 'Z-APC', 'name' => 'Zone']);

            self::assertFalse($staffA->can('view', $zone));
            self::assertTrue($manager->can('view', $zone));
        });
    }

    public function test_a_branch_scoped_user_cannot_view_another_branchs_rate_card_but_a_manager_can(): void
    {
        $company = $this->createCompany('APD');
        $branchA = $this->createBranch($company, 'DXB');
        $branchB = $this->createBranch($company, 'AUH');
        $staffA = $this->createUser($company, $branchA);
        $this->grantPermissions($staffA, ['rates.view']);
        $manager = $this->createUser($company);
        $this->grantPermissions($manager, ['rates.view', 'rates.manage']);

        $this->withTenant($company, function () use ($branchB, $staffA, $manager): void {
            $rateCard = RateCard::query()->create(['branch_id' => $branchB->getKey(), 'name' => 'Branch Rate', 'currency' => 'AED', 'base_fee' => 0, 'min_charge' => 0, 'is_active' => true]);

            self::assertFalse($staffA->can('view', $rateCard));
            self::assertTrue($manager->can('view', $rateCard));
        });
    }

    public function test_a_company_wide_rate_card_is_visible_to_every_branch(): void
    {
        $company = $this->createCompany('APE');
        $branchA = $this->createBranch($company, 'DXB');
        $staffA = $this->createUser($company, $branchA);
        $this->grantPermissions($staffA, ['rates.view']);

        $this->withTenant($company, function () use ($staffA): void {
            $rateCard = RateCard::query()->create(['name' => 'Company Wide', 'currency' => 'AED', 'base_fee' => 0, 'min_charge' => 0, 'is_active' => true]);

            self::assertTrue($staffA->can('view', $rateCard));
        });
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
