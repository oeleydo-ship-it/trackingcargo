<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RateCard;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomerDeletionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function asTenant(Company $company, Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }

    /** @return array{0: Company, 1: User} */
    private function companyWithManager(string $code): array
    {
        $company = $this->createCompany($code);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage', 'shipments.view']);

        return [$company, $actor];
    }

    public function test_a_customer_can_be_deleted_and_drops_out_of_the_list(): void
    {
        [$company, $actor] = $this->companyWithManager('CDA');
        $customer = $this->createCustomer($company, 'Dummy Name');

        $this->actingAs($actor)
            ->delete("/crm/customers/{$customer->getKey()}")
            ->assertRedirect('/crm/customers');

        $this->assertSoftDeleted('customers', ['id' => $customer->getKey()]);
        self::assertTrue(DB::table('audit_logs')->where('action', 'customer.deleted')->where('subject_id', $customer->getKey())->exists());

        $this->actingAs($actor)->get('/crm/customers')->assertInertia(fn ($page) => $page->has('customers.data', 0));
    }

    public function test_a_user_without_manage_permission_cannot_delete_a_customer(): void
    {
        $company = $this->createCompany('CDB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view']);
        $customer = $this->createCustomer($company, 'Keep Me');

        $this->actingAs($actor)->delete("/crm/customers/{$customer->getKey()}")->assertForbidden();

        $this->assertNotSoftDeleted('customers', ['id' => $customer->getKey()]);
    }

    public function test_a_company_cannot_delete_another_companys_customer(): void
    {
        [, $actor] = $this->companyWithManager('CDC');
        $other = $this->createCompany('CDD');
        $foreign = $this->createCustomer($other, 'Foreign Customer');

        $this->actingAs($actor)->delete("/crm/customers/{$foreign->getKey()}")->assertNotFound();

        $this->assertNotSoftDeleted('customers', ['id' => $foreign->getKey()]);
    }

    public function test_a_customer_with_invoices_is_kept_and_the_user_is_told_why(): void
    {
        [$company, $actor] = $this->companyWithManager('CDE');
        $branch = $this->createBranch($company, 'DXB');
        $customer = $this->createCustomer($company, 'Billed Customer', ['branch_id' => $branch->getKey()]);

        $this->asTenant($company, fn () => Invoice::query()->create([
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
            'invoice_number' => 'CDE-INV-000001',
            'status' => 'issued',
            'currency' => 'AED',
            'total' => 100,
            'amount_paid' => 0,
            'balance_due' => 100,
            'issue_date' => now()->toDateString(),
        ]));

        $this->actingAs($actor)
            ->delete("/crm/customers/{$customer->getKey()}")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'has invoices'));

        $this->assertNotSoftDeleted('customers', ['id' => $customer->getKey()]);
    }

    public function test_a_customer_with_a_rate_card_of_their_own_is_kept(): void
    {
        [$company, $actor] = $this->companyWithManager('CDF');
        $customer = $this->createCustomer($company, 'Negotiated Customer');

        $this->asTenant($company, fn () => RateCard::query()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'Negotiated',
            'currency' => 'AED',
        ]));

        $this->actingAs($actor)
            ->delete("/crm/customers/{$customer->getKey()}")
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'rate card'));

        $this->assertNotSoftDeleted('customers', ['id' => $customer->getKey()]);
    }

    public function test_shipments_booked_for_a_deleted_customer_keep_working(): void
    {
        [$company, $actor] = $this->companyWithManager('CDG');
        $branch = $this->createBranch($company, 'DXB');
        $customer = $this->createCustomer($company, 'Dummy Name');
        $shipment = $this->createShipment($company, $branch, ['customer_id' => $customer->getKey()]);

        $this->actingAs($actor)->delete("/crm/customers/{$customer->getKey()}")->assertRedirect('/crm/customers');

        $this->actingAs($actor)->get("/shipments/{$shipment->getKey()}")->assertOk();
        $this->actingAs($actor)->get('/shipments')->assertOk()->assertInertia(fn ($page) => $page->has('shipments.data', 1));
    }

    /**
     * A portal login is recognised by the customer it belongs to. If the login
     * outlived its customer it would look like an ordinary branch-less user and
     * be shown the whole company's shipments, so deleting the customer has to
     * take the login out of service.
     */
    public function test_deleting_a_customer_suspends_their_portal_login(): void
    {
        [$company, $actor] = $this->companyWithManager('CDH');
        $branch = $this->createBranch($company, 'DXB');
        $portalUser = $this->createUser($company);
        $this->grantPermissions($portalUser, ['shipments.view', 'tracking.view']);
        $customer = $this->createCustomer($company, 'Portal Customer');
        $this->asTenant($company, fn () => $customer->forceFill(['portal_user_id' => $portalUser->getKey()])->save());
        $this->createShipment($company, $branch, ['customer_id' => $customer->getKey()]);

        $this->actingAs($actor)->delete("/crm/customers/{$customer->getKey()}")->assertRedirect('/crm/customers');

        self::assertSame(UserStatus::Suspended, $portalUser->fresh()->status);

        $this->actingAs($portalUser->fresh())->get('/shipments')->assertForbidden();
    }
}
