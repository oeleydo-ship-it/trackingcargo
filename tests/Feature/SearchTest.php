<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class SearchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_find_a_shipment_by_tracking_number(): void
    {
        $company = $this->createCompany('SEA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view']);
        $shipment = $this->createShipment($company, $branch);

        $response = $this->actingAs($actor)->getJson('/search?q='.substr($shipment->tracking_number, -6));
        $response->assertOk();
        $response->assertJsonFragment(['id' => $shipment->getKey(), 'label' => $shipment->tracking_number]);
    }

    public function test_a_user_without_shipments_permission_gets_no_shipment_results(): void
    {
        $company = $this->createCompany('SEB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['customers.view']);
        $this->createShipment($company, $branch);

        $response = $this->actingAs($actor)->getJson('/search?q=GLX');
        $response->assertOk();
        $response->assertJson(['shipments' => []]);
    }

    public function test_a_short_query_returns_no_results_at_all(): void
    {
        $company = $this->createCompany('SEC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'customers.view', 'billing.view']);

        $response = $this->actingAs($actor)->getJson('/search?q=A');
        $response->assertOk();
        $response->assertJson(['shipments' => [], 'customers' => [], 'invoices' => []]);
    }

    public function test_a_customer_portal_user_only_finds_their_own_shipments(): void
    {
        $company = $this->createCompany('SED');
        $branch = $this->createBranch($company, 'DXB');

        $portalUser = $this->createUser($company);
        $this->grantPermissions($portalUser, ['shipments.view']);
        $customer = $this->withTenantReturn($company, function () use ($portalUser): Customer {
            $customer = Customer::query()->create(['customer_number' => 'SED-C-000001', 'type' => 'individual', 'name' => 'Portal Customer']);
            $customer->forceFill(['portal_user_id' => $portalUser->getKey()])->save();

            return $customer;
        });
        $otherCustomer = $this->createCustomer($company, 'Other', ['branch_id' => $branch->getKey()]);

        $shipmentOwn = $this->createShipment($company, $branch, ['customer_id' => $customer->getKey()]);
        $shipmentOther = $this->createShipment($company, $branch, ['customer_id' => $otherCustomer->getKey()]);

        $response = $this->actingAs($portalUser)->getJson('/search?q='.substr($shipmentOwn->tracking_number, 0, 6));
        $response->assertOk()->assertJsonFragment(['id' => $shipmentOwn->getKey()]);
        self::assertStringNotContainsString($shipmentOther->tracking_number, $response->getContent());
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
