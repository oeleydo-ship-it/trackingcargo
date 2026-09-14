<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class PaymentTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_partial_payment_then_a_final_payment_moves_the_invoice_through_partially_paid_to_paid(): void
    {
        [$company, $branch, $actor, $customer] = $this->setUpTenant('PYA');
        $invoice = $this->issuedInvoice($company, $branch, $actor, $customer, 100);

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/payments", [
                'idempotency_key' => 'pay-a-1',
                'amount' => 40,
                'method' => 'cash',
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($invoice): void {
            $invoice->refresh();
            self::assertSame('partially_paid', $invoice->status->value);
            self::assertEqualsWithDelta(40.0, (float) $invoice->amount_paid, 0.001);
            self::assertEqualsWithDelta(60.0, (float) $invoice->balance_due, 0.001);
        });

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/payments", [
                'idempotency_key' => 'pay-a-2',
                'amount' => 60,
                'method' => 'bank_transfer',
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($invoice): void {
            $invoice->refresh();
            self::assertSame('paid', $invoice->status->value);
            self::assertEqualsWithDelta(100.0, (float) $invoice->amount_paid, 0.001);
            self::assertEqualsWithDelta(0.0, (float) $invoice->balance_due, 0.001);
        });
    }

    public function test_a_payment_cannot_exceed_the_outstanding_balance(): void
    {
        [$company, $branch, $actor, $customer] = $this->setUpTenant('PYB');
        $invoice = $this->issuedInvoice($company, $branch, $actor, $customer, 100);

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/payments", [
                'idempotency_key' => 'pay-b-1',
                'amount' => 150,
                'method' => 'cash',
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_retrying_the_same_idempotency_key_does_not_duplicate_the_payment(): void
    {
        [$company, $branch, $actor, $customer] = $this->setUpTenant('PYC');
        $invoice = $this->issuedInvoice($company, $branch, $actor, $customer, 100);
        $payload = ['idempotency_key' => 'pay-c-1', 'amount' => 25, 'method' => 'cash'];

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/payments", $payload)->assertRedirect();
        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/payments", $payload)->assertRedirect();

        $this->withTenant($company, function () use ($invoice): void {
            self::assertSame(1, Payment::query()->where('idempotency_key', 'pay-c-1')->count());
            $invoice->refresh();
            self::assertEqualsWithDelta(25.0, (float) $invoice->amount_paid, 0.001);
        });
    }

    public function test_a_payment_cannot_be_recorded_against_a_draft_invoice(): void
    {
        [$company, $branch, $actor, $customer] = $this->setUpTenant('PYD');
        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices", ['currency' => 'AED']);
        $invoice = $this->withTenantReturn($company, fn () => Invoice::query()->where('customer_id', $customer->getKey())->firstOrFail());

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/payments", [
                'idempotency_key' => 'pay-d-1',
                'amount' => 10,
                'method' => 'cash',
            ])
            ->assertSessionHasErrors('invoice');
    }

    private function issuedInvoice(Company $company, Branch $branch, User $actor, Customer $customer, float $amount): Invoice
    {
        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices", ['currency' => 'AED']);
        $invoice = $this->withTenantReturn($company, fn () => Invoice::query()->where('customer_id', $customer->getKey())->firstOrFail());

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/items", [
            'from_shipment' => false,
            'description' => 'Freight charges',
            'quantity' => 1,
            'unit_price' => $amount,
        ]);

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/transitions", ['status' => 'issued']);

        return $invoice;
    }

    /** @return array{0: Company, 1: Branch, 2: User, 3: Customer} */
    private function setUpTenant(string $code): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['billing.view', 'billing.manage', 'payments.view', 'payments.manage']);
        $customer = $this->createCustomer($company, $code.' Customer', ['branch_id' => $branch->getKey()]);

        return [$company, $branch, $actor, $customer];
    }

    private function withTenant(Company $company, Closure $callback): void
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $callback();
        } finally {
            $context->forget();
        }
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
