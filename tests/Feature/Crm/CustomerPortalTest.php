<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomerPortalTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_invite_a_portal_user_who_is_linked_and_assigned_the_customer_role(): void
    {
        Notification::fake();

        $company = $this->createCompany('CPA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);
        $customer = $this->createCustomer($company, 'Portal Customer');

        $this->seedCustomerRole($company);

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/portal", [
                'name' => 'Portal Person',
                'email' => 'portal@example.test',
            ])
            ->assertRedirect();

        $portalUser = User::query()->where('email', 'portal@example.test')->firstOrFail();
        self::assertSame(UserStatus::Invited, $portalUser->status);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $customer->refresh();
            self::assertSame($portalUser->getKey(), $customer->portal_user_id);
            self::assertTrue($portalUser->hasPermission('shipments.view'));
        } finally {
            $context->forget();
        }
    }

    public function test_a_customer_cannot_be_linked_to_a_second_portal_account(): void
    {
        Notification::fake();

        $company = $this->createCompany('CPB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);
        $customer = $this->createCustomer($company, 'Already Linked');
        $this->seedCustomerRole($company);

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/portal", [
            'name' => 'First Portal User', 'email' => 'first-portal@example.test',
        ]);

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/portal", [
                'name' => 'Second Portal User', 'email' => 'second-portal@example.test',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_a_portal_invite_cannot_target_another_companys_customer(): void
    {
        Notification::fake();

        $companyA = $this->createCompany('CPC');
        $companyB = $this->createCompany('CPD');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['customers.view', 'customers.manage']);
        $customerB = $this->createCustomer($companyB, 'Foreign Customer');

        $this->actingAs($actorA)
            ->post("/crm/customers/{$customerB->getKey()}/portal", [
                'name' => 'Should Fail', 'email' => 'should-fail@example.test',
            ])
            ->assertNotFound();
    }

    private function seedCustomerRole(Company $company): void
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => 'shipments.view'],
                ['name' => 'Shipments: View', 'group' => 'shipments', 'platform_only' => false],
            );
            $role = Role::query()->create(['name' => 'Customer', 'slug' => 'customer']);
            $role->permissions()->sync([$permission->getKey()]);
        } finally {
            $context->forget();
        }
    }
}
