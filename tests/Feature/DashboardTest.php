<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_without_shipments_permission_sees_no_shipment_widget(): void
    {
        $company = $this->createCompany('DBA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['billing.view']);

        $response = $this->actingAs($actor)->get('/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('shipments.visible', false)
            ->where('billing.visible', true)
            ->where('customs', null));
    }

    public function test_shipment_counts_reflect_real_data_for_a_branch_scoped_user(): void
    {
        $company = $this->createCompany('DBB');
        $branchA = $this->createBranch($company, 'DXB');
        $branchB = $this->createBranch($company, 'AUH');
        $actor = $this->createUser($company, $branchA);
        $this->grantPermissions($actor, ['shipments.view']);

        $this->createShipment($company, $branchA, ['status' => 'in_transit']);
        $this->createShipment($company, $branchA, ['status' => 'exception']);
        $this->createShipment($company, $branchB, ['status' => 'in_transit']);

        $response = $this->actingAs($actor)->get('/dashboard');
        $response->assertInertia(fn ($page) => $page
            ->where('shipments.visible', true)
            ->where('shipments.inTransit', 1)
            ->where('shipments.exceptions', 1));
    }

    public function test_a_customer_portal_users_dashboard_only_counts_their_own_shipments_and_invoices(): void
    {
        $company = $this->createCompany('DBC');
        $branch = $this->createBranch($company, 'DXB');

        $portalUser = $this->createUser($company);
        $this->grantPermissions($portalUser, ['shipments.view', 'billing.view']);
        $customer = $this->withTenantReturn($company, function () use ($portalUser): Customer {
            $customer = Customer::query()->create(['customer_number' => 'DBC-C-000001', 'type' => 'individual', 'name' => 'Portal Customer']);
            $customer->forceFill(['portal_user_id' => $portalUser->getKey()])->save();

            return $customer;
        });

        $otherCustomer = $this->createCustomer($company, 'Other Customer', ['branch_id' => $branch->getKey()]);

        $this->createShipment($company, $branch, ['customer_id' => $customer->getKey(), 'status' => 'in_transit']);
        $this->createShipment($company, $branch, ['customer_id' => $otherCustomer->getKey(), 'status' => 'in_transit']);

        $this->withTenantReturn($company, fn () => Invoice::query()->create([
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
            'invoice_number' => 'DBC-A-INV-000001',
            'status' => InvoiceStatus::Issued,
            'currency' => 'AED',
            'total' => 100,
            'balance_due' => 100,
        ]));
        $this->withTenantReturn($company, fn () => Invoice::query()->create([
            'branch_id' => $branch->getKey(),
            'customer_id' => $otherCustomer->getKey(),
            'invoice_number' => 'DBC-B-INV-000001',
            'status' => InvoiceStatus::Issued,
            'currency' => 'AED',
            'total' => 999,
            'balance_due' => 999,
        ]));

        $response = $this->actingAs($portalUser)->get('/dashboard');
        $response->assertInertia(fn ($page) => $page
            ->where('shipments.inTransit', 1)
            ->where('billing.outstandingBalance', '100.00'));
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
