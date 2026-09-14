<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Address;
use App\Models\Customer;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomerAddressTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_add_an_address(): void
    {
        $company = $this->createCompany('CAA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);
        $customer = $this->createCustomer($company, 'Address Host');

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/addresses", [
                'type' => 'shipping',
                'line1' => '1 Warehouse Road',
                'city' => 'Dubai',
                'country_code' => 'AE',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('addresses', [
            'addressable_type' => Customer::class,
            'addressable_id' => $customer->getKey(),
            'line1' => '1 Warehouse Road',
        ]);
    }

    public function test_setting_a_new_default_address_unsets_the_previous_default_of_the_same_type(): void
    {
        $company = $this->createCompany('CAB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);
        $customer = $this->createCustomer($company, 'Default Toggle Host');

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/addresses", [
            'type' => 'shipping', 'line1' => 'First', 'city' => 'Dubai', 'country_code' => 'AE', 'is_default' => true,
        ]);
        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/addresses", [
            'type' => 'shipping', 'line1' => 'Second', 'city' => 'Dubai', 'country_code' => 'AE', 'is_default' => true,
        ]);
        // A default billing address must not be affected by the shipping default toggle.
        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/addresses", [
            'type' => 'billing', 'line1' => 'Billing', 'city' => 'Dubai', 'country_code' => 'AE', 'is_default' => true,
        ]);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $shippingDefaults = Address::query()->where('addressable_id', $customer->getKey())->where('type', 'shipping')->where('is_default', true)->pluck('line1');
            $billingDefaults = Address::query()->where('addressable_id', $customer->getKey())->where('type', 'billing')->where('is_default', true)->pluck('line1');
        } finally {
            $context->forget();
        }

        self::assertSame(['Second'], $shippingDefaults->all());
        self::assertSame(['Billing'], $billingDefaults->all());
    }

    public function test_an_address_cannot_be_added_to_another_companys_customer(): void
    {
        $companyA = $this->createCompany('CAC');
        $companyB = $this->createCompany('CAD');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['customers.view', 'customers.manage']);
        $customerB = $this->createCustomer($companyB, 'Foreign Customer');

        $this->actingAs($actorA)
            ->post("/crm/customers/{$customerB->getKey()}/addresses", [
                'type' => 'shipping', 'line1' => 'Should fail', 'city' => 'Dubai', 'country_code' => 'AE',
            ])
            ->assertNotFound();
    }

    public function test_an_address_from_a_different_customer_cannot_be_deleted_via_a_sibling_customer(): void
    {
        $company = $this->createCompany('CAE');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);
        $customerA = $this->createCustomer($company, 'Customer A');
        $customerB = $this->createCustomer($company, 'Customer B');

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $address = $customerA->addresses()->create(['type' => 'shipping', 'line1' => 'A Only', 'city' => 'Dubai', 'country_code' => 'AE']);
        } finally {
            $context->forget();
        }

        $this->actingAs($actor)
            ->delete("/crm/customers/{$customerB->getKey()}/addresses/{$address->getKey()}")
            ->assertNotFound();
    }
}
