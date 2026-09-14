<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\CustomerContact;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomerContactTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_add_and_remove_a_contact(): void
    {
        $company = $this->createCompany('CCA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);
        $customer = $this->createCustomer($company, 'Contact Host');

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/contacts", [
                'name' => 'Jane Ops',
                'email' => 'jane@example.test',
                'is_primary' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_contacts', ['customer_id' => $customer->getKey(), 'name' => 'Jane Ops']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $contactId = CustomerContact::query()->where('customer_id', $customer->getKey())->firstOrFail()->getKey();
        } finally {
            $context->forget();
        }

        $this->actingAs($actor)
            ->delete("/crm/customers/{$customer->getKey()}/contacts/{$contactId}")
            ->assertRedirect();

        $this->assertSoftDeleted('customer_contacts', ['id' => $contactId]);
    }

    public function test_a_contact_cannot_be_attached_to_another_companys_customer(): void
    {
        $companyA = $this->createCompany('CCB');
        $companyB = $this->createCompany('CCC');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['customers.view', 'customers.manage']);
        $customerB = $this->createCustomer($companyB, 'Foreign Customer');

        $this->actingAs($actorA)
            ->post("/crm/customers/{$customerB->getKey()}/contacts", ['name' => 'Should Fail'])
            ->assertNotFound();
    }

    public function test_a_contact_from_a_different_customer_cannot_be_deleted_via_a_sibling_customer(): void
    {
        $company = $this->createCompany('CCD');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);
        $customerA = $this->createCustomer($company, 'Customer A');
        $customerB = $this->createCustomer($company, 'Customer B');

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $contact = $customerA->contacts()->create(['name' => 'Belongs To A']);
        } finally {
            $context->forget();
        }

        $this->actingAs($actor)
            ->delete("/crm/customers/{$customerB->getKey()}/contacts/{$contact->getKey()}")
            ->assertNotFound();

        $this->assertDatabaseHas('customer_contacts', ['id' => $contact->getKey(), 'deleted_at' => null]);
    }
}
