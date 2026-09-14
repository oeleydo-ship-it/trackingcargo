<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomerNoteTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_add_a_note_attributed_to_them(): void
    {
        $company = $this->createCompany('CNA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);
        $customer = $this->createCustomer($company, 'Note Host');

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/notes", ['body' => 'Called about a delayed pickup.'])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_notes', [
            'customer_id' => $customer->getKey(),
            'author_id' => $actor->getKey(),
            'body' => 'Called about a delayed pickup.',
        ]);
    }

    public function test_a_note_cannot_be_added_to_another_companys_customer(): void
    {
        $companyA = $this->createCompany('CNB');
        $companyB = $this->createCompany('CNC');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['customers.view', 'customers.manage']);
        $customerB = $this->createCustomer($companyB, 'Foreign Customer');

        $this->actingAs($actorA)
            ->post("/crm/customers/{$customerB->getKey()}/notes", ['body' => 'Should fail'])
            ->assertNotFound();
    }
}
