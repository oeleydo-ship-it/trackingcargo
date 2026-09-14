<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Enums\AddressType;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The type-ahead behind the booking form's consignor/consignee pickers.
 */
final class CustomerLookupTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_search_returns_matching_customers_with_their_addresses(): void
    {
        $company = $this->createCompany('CLA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['customers.view']);

        $customer = $this->createCustomer($company, 'Al Maha Trading LLC', ['company_name' => 'Al Maha Trading LLC']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            $customer->addresses()->create([
                'type' => AddressType::Delivery,
                'line1' => '24 Mabini Street',
                'city' => 'Manila',
                'country_code' => 'PH',
                'is_default' => true,
            ]);
        } finally {
            $context->forget();
        }

        $response = $this->actingAs($actor)->getJson('/crm/customers/lookup?q=maha');

        $response->assertOk();

        $payload = $response->json('customers');

        self::assertCount(1, $payload);
        self::assertSame('Al Maha Trading LLC', $payload[0]['name']);
        self::assertSame($customer->customer_number, $payload[0]['customer_number']);
        self::assertCount(1, $payload[0]['addresses']);
        self::assertSame('24 Mabini Street', $payload[0]['addresses'][0]['line1']);
        self::assertSame('delivery', $payload[0]['addresses'][0]['type']);
    }

    public function test_a_single_character_term_returns_nothing(): void
    {
        $company = $this->createCompany('CLB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['customers.view']);
        $this->createCustomer($company, 'Al Maha Trading LLC');

        $this->actingAs($actor)
            ->getJson('/crm/customers/lookup?q=a')
            ->assertOk()
            ->assertExactJson(['customers' => []]);
    }

    public function test_the_lookup_never_crosses_company_boundaries(): void
    {
        $company = $this->createCompany('CLC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['customers.view']);

        $otherCompany = $this->createCompany('CLD');
        $this->createCustomer($otherCompany, 'Al Maha Trading LLC');

        $this->actingAs($actor)
            ->getJson('/crm/customers/lookup?q=maha')
            ->assertOk()
            ->assertExactJson(['customers' => []]);
    }

    public function test_a_user_without_customer_or_shipment_permissions_gets_nothing(): void
    {
        $company = $this->createCompany('CLE');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['tracking.view']);
        $this->createCustomer($company, 'Al Maha Trading LLC');

        $this->actingAs($actor)
            ->getJson('/crm/customers/lookup?q=maha')
            ->assertOk()
            ->assertExactJson(['customers' => []]);
    }

    public function test_a_booking_clerk_without_crm_access_can_still_search(): void
    {
        $company = $this->createCompany('CLF');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $this->createCustomer($company, 'Al Maha Trading LLC');

        $this->actingAs($actor)
            ->getJson('/crm/customers/lookup?q=maha')
            ->assertOk()
            ->assertJsonCount(1, 'customers');
    }

    public function test_an_inactive_customer_is_not_offered(): void
    {
        $company = $this->createCompany('CLG');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['customers.view']);
        $this->createCustomer($company, 'Al Maha Trading LLC', ['status' => 'inactive']);

        $this->actingAs($actor)
            ->getJson('/crm/customers/lookup?q=maha')
            ->assertOk()
            ->assertExactJson(['customers' => []]);
    }

    public function test_a_customer_can_be_found_by_number_or_company_name(): void
    {
        $company = $this->createCompany('CLH');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['customers.view']);

        $customer = $this->createCustomer($company, 'Fatima Al Suwaidi', ['company_name' => 'Suwaidi Logistics']);

        $this->actingAs($actor)
            ->getJson('/crm/customers/lookup?q='.urlencode($customer->customer_number))
            ->assertOk()
            ->assertJsonCount(1, 'customers');

        $this->actingAs($actor)
            ->getJson('/crm/customers/lookup?q=Suwaidi')
            ->assertOk()
            ->assertJsonCount(1, 'customers');

        $this->actingAs($actor)
            ->getJson('/crm/customers/lookup?q=nobody')
            ->assertOk()
            ->assertJsonCount(0, 'customers');
    }

    public function test_a_guest_is_redirected_rather_than_served_the_customer_book(): void
    {
        $this->getJson('/crm/customers/lookup?q=maha')->assertUnauthorized();
    }

    public function test_the_lookup_route_does_not_shadow_the_customer_show_route(): void
    {
        $company = $this->createCompany('CLI');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['customers.view']);
        $customer = $this->createCustomer($company, 'Al Maha Trading LLC');

        $this->actingAs($actor)
            ->get("/crm/customers/{$customer->getKey()}")
            ->assertOk();
    }
}
