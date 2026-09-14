<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\InvoiceStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Phase 10 security-review finding: `customer.role` grants `shipments.view`
 * and `billing.view` company-wide (for a customer's own portal), but
 * ShipmentPolicy/InvoicePolicy's branch checks used
 * `$user->branch_id === null` as a "staff with no branch restriction, see
 * everything" bypass — and a customer portal login's branch_id is *always*
 * null (CustomerPortalService::invite() never sets it), so that bypass
 * fired for portal logins too. A customer could browse
 * `/shipments` and see every other customer's shipments (declared values,
 * consignor/consignee contact details, tracking notes), and could open
 * `/crm/customers/{anyCustomerId}/invoices/{anyInvoiceId}` for any other
 * customer's invoice/payment history, by URL alone. Fixed by
 * User::customerProfile() + an explicit "portal login sees only its own
 * customer_id" branch in both policies (checked before, not after, the
 * staff bypass) and a matching query-level filter in
 * ShipmentController::index().
 */
final class CustomerPortalScopeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_customer_portal_user_cannot_view_another_customers_shipment(): void
    {
        [$company, $branch] = $this->setUpTenant('CPA');
        [$customerA, $portalA] = $this->createPortalCustomer($company, 'CPA Customer A');
        [$customerB] = $this->createPortalCustomer($company, 'CPA Customer B');

        $shipmentB = $this->withTenantReturn($company, fn () => $this->createShipment($company, $branch, ['customer_id' => $customerB->getKey()]));

        $this->actingAs($portalA)->get("/shipments/{$shipmentB->getKey()}")->assertForbidden();
    }

    public function test_a_customer_portal_users_shipment_list_only_shows_their_own_shipments(): void
    {
        [$company, $branch] = $this->setUpTenant('CPB');
        [$customerA, $portalA] = $this->createPortalCustomer($company, 'CPB Customer A');
        [$customerB] = $this->createPortalCustomer($company, 'CPB Customer B');

        $shipmentA = $this->withTenantReturn($company, fn () => $this->createShipment($company, $branch, ['customer_id' => $customerA->getKey()]));
        $this->withTenantReturn($company, fn () => $this->createShipment($company, $branch, ['customer_id' => $customerB->getKey()]));

        $response = $this->actingAs($portalA)->get('/shipments');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('shipments.data', 1)
            ->where('shipments.data.0.id', $shipmentA->getKey()));
    }

    public function test_a_customer_portal_user_can_view_their_own_shipment(): void
    {
        [$company, $branch] = $this->setUpTenant('CPC');
        [$customerA, $portalA] = $this->createPortalCustomer($company, 'CPC Customer A');
        $shipmentA = $this->withTenantReturn($company, fn () => $this->createShipment($company, $branch, ['customer_id' => $customerA->getKey()]));

        $this->actingAs($portalA)->get("/shipments/{$shipmentA->getKey()}")->assertOk();
    }

    public function test_a_customer_portal_user_cannot_view_another_customers_invoice(): void
    {
        [$company, $branch] = $this->setUpTenant('CPD');
        [$customerA, $portalA] = $this->createPortalCustomer($company, 'CPD Customer A');
        [$customerB] = $this->createPortalCustomer($company, 'CPD Customer B');

        $invoiceB = $this->withTenantReturn($company, fn () => Invoice::query()->create([
            'branch_id' => $branch->getKey(),
            'customer_id' => $customerB->getKey(),
            'invoice_number' => 'CPD-B-INV-000001',
            'status' => InvoiceStatus::Issued,
            'currency' => 'AED',
        ]));

        $this->actingAs($portalA)->get("/crm/customers/{$customerB->getKey()}/invoices/{$invoiceB->getKey()}")->assertForbidden();
    }

    public function test_a_customer_portal_user_can_view_their_own_invoice(): void
    {
        [$company, $branch] = $this->setUpTenant('CPE');
        [$customerA, $portalA] = $this->createPortalCustomer($company, 'CPE Customer A');

        $invoiceA = $this->withTenantReturn($company, fn () => Invoice::query()->create([
            'branch_id' => $branch->getKey(),
            'customer_id' => $customerA->getKey(),
            'invoice_number' => 'CPE-A-INV-000001',
            'status' => InvoiceStatus::Issued,
            'currency' => 'AED',
        ]));

        $this->actingAs($portalA)->get("/crm/customers/{$customerA->getKey()}/invoices/{$invoiceA->getKey()}")->assertOk();
    }

    /** @return array{0: Company, 1: Branch} */
    private function setUpTenant(string $code): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');

        return [$company, $branch];
    }

    /** @return array{0: Customer, 1: User} */
    private function createPortalCustomer(Company $company, string $name): array
    {
        $portalUser = $this->createUser($company);
        $this->grantPermissions($portalUser, ['shipments.view', 'tracking.view', 'billing.view', 'payments.view']);

        $customer = $this->withTenantReturn($company, function () use ($name, $portalUser): Customer {
            $customer = Customer::query()->create([
                'customer_number' => strtoupper(substr(md5($name), 0, 10)),
                'type' => 'individual',
                'name' => $name,
            ]);
            $customer->forceFill(['portal_user_id' => $portalUser->getKey()])->save();

            return $customer;
        });

        return [$customer, $portalUser];
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
