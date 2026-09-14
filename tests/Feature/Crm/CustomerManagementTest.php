<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Enums\AddressType;
use App\Models\Address;
use App\Models\Company;
use App\Models\Customer;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomerManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_create_a_customer_with_a_generated_number(): void
    {
        $company = $this->createCompany('CRA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);

        $this->actingAs($actor)
            ->post('/crm/customers', [
                'type' => 'business',
                'name' => 'Acme Freight LLC',
                'company_name' => 'Acme Freight',
                'email' => 'ops@acme.test',
                'phone' => '+971500000000',
                'tax_id' => 'TRN-1',
            ])
            ->assertRedirect();

        $customer = $this->findCustomer($company, 'Acme Freight LLC');
        self::assertSame($company->getKey(), $customer->company_id);
        self::assertSame("{$company->code}-C-000001", $customer->customer_number);
    }

    public function test_a_second_customer_gets_the_next_sequential_number(): void
    {
        $company = $this->createCompany('CRB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);

        $this->actingAs($actor)->post('/crm/customers', ['type' => 'individual', 'name' => 'First Customer']);
        $this->actingAs($actor)->post('/crm/customers', ['type' => 'individual', 'name' => 'Second Customer']);

        $first = $this->findCustomer($company, 'First Customer');
        $second = $this->findCustomer($company, 'Second Customer');

        self::assertSame("{$company->code}-C-000001", $first->customer_number);
        self::assertSame("{$company->code}-C-000002", $second->customer_number);
    }

    /**
     * The address book of a customer, read inside the tenant context the
     * company scope requires.
     *
     * @return Collection<int, Address>
     */
    private function addressesOf(Company $company, string $name): Collection
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return Customer::query()->where('name', $name)->firstOrFail()->addresses()->get();
        } finally {
            $context->forget();
        }
    }

    private function findCustomer(Company $company, string $name): Customer
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return Customer::query()->where('name', $name)->firstOrFail();
        } finally {
            $context->forget();
        }
    }

    public function test_a_user_without_permission_cannot_create_a_customer(): void
    {
        $company = $this->createCompany('CRC');
        $actor = $this->createUser($company);

        $this->actingAs($actor)
            ->post('/crm/customers', ['type' => 'individual', 'name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_a_company_cannot_view_or_modify_another_companys_customer(): void
    {
        $companyA = $this->createCompany('CRD');
        $companyB = $this->createCompany('CRE');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['customers.view', 'customers.manage']);
        $customerB = $this->createCustomer($companyB, 'Other Co Customer');

        $this->actingAs($actorA)->get("/crm/customers/{$customerB->getKey()}")->assertNotFound();

        $this->actingAs($actorA)
            ->patch("/crm/customers/{$customerB->getKey()}", [
                'type' => 'individual',
                'name' => 'Hijacked',
                'status' => 'active',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('customers', ['id' => $customerB->getKey(), 'name' => 'Other Co Customer']);
    }

    public function test_customer_listing_never_includes_another_companys_customers(): void
    {
        $companyA = $this->createCompany('CRF');
        $companyB = $this->createCompany('CRG');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['customers.view']);
        $this->createCustomer($companyA, 'Visible Customer');
        $this->createCustomer($companyB, 'Hidden Customer');

        $response = $this->actingAs($actorA)->get('/crm/customers');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Visible Customer'));
    }

    public function test_search_matches_name_company_phone_email_tax_id_and_identification_number(): void
    {
        $company = $this->createCompany('CRH');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view']);

        $this->createCustomer($company, 'Findable Name');
        $this->createCustomer($company, 'By Company', ['company_name' => 'Searchable Co']);
        $this->createCustomer($company, 'By Phone', ['phone' => '+971501234567']);
        $this->createCustomer($company, 'By Email', ['email' => 'unique-needle@example.test']);
        $this->createCustomer($company, 'By Tax Id', ['tax_id' => 'TAXNEEDLE123']);
        $this->createCustomer($company, 'By Identification', ['identification_number' => 'IDNEEDLE999']);
        $this->createCustomer($company, 'Noise Customer');

        foreach ([
            'Findable Name' => 'Findable',
            'Searchable Co' => 'Searchable Co',
            '+971501234567' => '971501234567',
            'unique-needle@example.test' => 'unique-needle',
            'TAXNEEDLE123' => 'TAXNEEDLE',
            'IDNEEDLE999' => 'IDNEEDLE',
        ] as $expectedName => $term) {
            $response = $this->actingAs($actor)->get('/crm/customers?q='.urlencode($term));
            $response->assertInertia(fn ($page) => $page->has('customers.data', 1));
        }
    }

    public function test_a_customer_can_be_created_with_a_first_address_in_one_pass(): void
    {
        $company = $this->createCompany('CRZ');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);

        $this->actingAs($actor)
            ->post('/crm/customers', [
                'type' => 'business',
                'name' => 'Acme Freight LLC',
                'address' => [
                    'type' => 'delivery',
                    'label' => 'Head office',
                    'line1' => '24 Mabini Street',
                    'line2' => 'Unit 5B',
                    'city' => 'Manila',
                    'state' => 'Metro Manila',
                    'postal_code' => '1000',
                    'country_code' => 'ph',
                    'contact_name' => 'Reception',
                    'contact_phone' => '+63 2 555 0101',
                ],
            ])
            ->assertRedirect();

        $address = $this->addressesOf($company, 'Acme Freight LLC')->sole();

        self::assertSame('24 Mabini Street', $address->line1);
        self::assertSame('Unit 5B', $address->line2);
        self::assertSame('Manila', $address->city);
        self::assertSame('Metro Manila', $address->state);
        self::assertSame('1000', $address->postal_code);
        self::assertSame('Head office', $address->label);
        self::assertSame('Reception', $address->contact_name);
        self::assertSame(AddressType::Delivery, $address->type);

        // Upper-cased on the way in, and the customer's first address for its
        // type, so the booking form will offer it up later.
        self::assertSame('PH', $address->country_code);
        self::assertTrue($address->is_default);
    }

    public function test_creating_a_customer_without_an_address_writes_none(): void
    {
        $company = $this->createCompany('CRY');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);

        $this->actingAs($actor)
            ->post('/crm/customers', ['type' => 'individual', 'name' => 'Walk In Customer'])
            ->assertRedirect();

        self::assertCount(0, $this->addressesOf($company, 'Walk In Customer'));
    }

    public function test_a_blank_address_form_is_not_treated_as_an_address(): void
    {
        $company = $this->createCompany('CRX');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);

        // What the create form actually posts when the clerk ignores the
        // optional address block: a type default and empty strings.
        $this->actingAs($actor)
            ->post('/crm/customers', [
                'type' => 'individual',
                'name' => 'Walk In Customer',
                'address' => [
                    'type' => '', 'label' => '', 'line1' => '', 'line2' => '', 'city' => '',
                    'state' => '', 'postal_code' => '', 'country_code' => '', 'contact_name' => '', 'contact_phone' => '',
                ],
            ])
            ->assertRedirect();

        self::assertCount(0, $this->addressesOf($company, 'Walk In Customer'));
    }

    public function test_a_half_filled_address_is_rejected(): void
    {
        $company = $this->createCompany('CRW');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);

        $this->actingAs($actor)
            ->post('/crm/customers', [
                'type' => 'business',
                'name' => 'Acme Freight LLC',
                'address' => ['line2' => 'Warehouse 4'],
            ])
            ->assertSessionHasErrors(['address.line1', 'address.city', 'address.country_code']);
    }

    public function test_an_address_defaults_to_shipping_when_no_type_is_chosen(): void
    {
        $company = $this->createCompany('CRV');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);

        $this->actingAs($actor)
            ->post('/crm/customers', [
                'type' => 'business',
                'name' => 'Acme Freight LLC',
                'address' => ['line1' => '12 Al Quoz', 'city' => 'Dubai', 'country_code' => 'AE'],
            ])
            ->assertRedirect();

        self::assertSame(AddressType::Shipping, $this->addressesOf($company, 'Acme Freight LLC')->sole()->type);
    }
}
