<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RateCard;
use App\Models\Shipment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class InvoiceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_draft_invoice_can_receive_a_shipment_line_item_and_a_manual_line_item(): void
    {
        [$company, $branch, $actor, $customer] = $this->setUpTenant('INA');
        $shipment = $this->createShipment($company, $branch, ['customer_id' => $customer->getKey(), 'chargeable_weight_kg' => 5]);
        $rateCard = $this->createRateCard($company, ['base_fee' => 10, 'min_charge' => 0], [['min_weight_kg' => 0, 'max_weight_kg' => null, 'price_per_kg' => 4]]);

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices", ['currency' => 'AED'])
            ->assertRedirect();

        $invoice = $this->withTenantReturn($company, fn () => Invoice::query()->where('customer_id', $customer->getKey())->firstOrFail());

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/items", [
                'from_shipment' => true,
                'shipment_id' => $shipment->getKey(),
                'rate_card_id' => $rateCard->getKey(),
            ])
            ->assertRedirect();

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/items", [
                'from_shipment' => false,
                'description' => 'Packing materials',
                'quantity' => 2,
                'unit_price' => 15,
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($invoice): void {
            $invoice->refresh();
            self::assertSame(2, $invoice->items()->count());
            // shipment item: base 10 + 5kg * 4 = 30. manual item: 2 * 15 = 30. subtotal = 60.
            self::assertEqualsWithDelta(60.0, (float) $invoice->subtotal, 0.001);
            self::assertEqualsWithDelta(60.0, (float) $invoice->total, 0.001);
            self::assertEqualsWithDelta(60.0, (float) $invoice->balance_due, 0.001);
        });
    }

    public function test_line_items_cannot_be_changed_once_the_invoice_is_issued(): void
    {
        [$company, $branch, $actor, $customer] = $this->setUpTenant('INB');
        $invoice = $this->createDraftInvoiceWithItem($company, $branch, $actor, $customer, 50);

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/transitions", ['status' => 'issued'])->assertRedirect();

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/items", [
                'from_shipment' => false,
                'description' => 'Late addition',
                'quantity' => 1,
                'unit_price' => 5,
            ])
            ->assertSessionHasErrors('invoice');
    }

    public function test_issuing_an_invoice_is_blocked_when_it_would_exceed_the_customers_credit_limit(): void
    {
        [$company, $branch, $actor, $customer] = $this->setUpTenant('INC', creditLimit: 40);
        $invoice = $this->createDraftInvoiceWithItem($company, $branch, $actor, $customer, 50);

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/transitions", ['status' => 'issued'])
            ->assertSessionHasErrors('status');

        $this->withTenant($company, function () use ($invoice): void {
            $invoice->refresh();
            self::assertSame('draft', $invoice->status->value);
        });
    }

    public function test_an_invoice_with_a_payment_applied_cannot_be_voided(): void
    {
        [$company, $branch, $actor, $customer] = $this->setUpTenant('IND');
        $invoice = $this->createDraftInvoiceWithItem($company, $branch, $actor, $customer, 50);
        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/transitions", ['status' => 'issued'])->assertRedirect();

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/payments", [
                'idempotency_key' => 'pay-void-1',
                'amount' => 10,
                'method' => 'cash',
            ])
            ->assertRedirect();

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/transitions", ['status' => 'void'])
            ->assertSessionHasErrors('status');
    }

    public function test_paid_and_partially_paid_are_not_reachable_through_the_transition_endpoint(): void
    {
        [$company, $branch, $actor, $customer] = $this->setUpTenant('INE');
        $invoice = $this->createDraftInvoiceWithItem($company, $branch, $actor, $customer, 50);
        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/transitions", ['status' => 'issued'])->assertRedirect();

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/transitions", ['status' => 'paid'])
            ->assertSessionHasErrors('status');
    }

    public function test_a_branch_scoped_user_can_create_an_invoice_for_a_company_wide_customer(): void
    {
        $company = $this->createCompany('INF');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['billing.view', 'billing.manage']);
        $customer = $this->createCustomer($company, 'Company wide customer');

        $this->actingAs($actor)
            ->post("/crm/customers/{$customer->getKey()}/invoices", ['currency' => 'AED'])
            ->assertRedirect();

        $this->withTenant($company, function () use ($customer): void {
            self::assertSame(1, Invoice::query()->where('customer_id', $customer->getKey())->count());
        });
    }

    private function createDraftInvoiceWithItem(Company $company, Branch $branch, User $actor, Customer $customer, float $amount): Invoice
    {
        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices", ['currency' => 'AED']);
        $invoice = $this->withTenantReturn($company, fn () => Invoice::query()->where('customer_id', $customer->getKey())->firstOrFail());

        $this->actingAs($actor)->post("/crm/customers/{$customer->getKey()}/invoices/{$invoice->getKey()}/items", [
            'from_shipment' => false,
            'description' => 'Freight charges',
            'quantity' => 1,
            'unit_price' => $amount,
        ]);

        return $invoice;
    }

    /** @return array{0: Company, 1: Branch, 2: User, 3: Customer} */
    private function setUpTenant(string $code, ?float $creditLimit = null): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['billing.view', 'billing.manage', 'payments.view', 'payments.manage', 'rates.view', 'rates.manage', 'shipments.view']);
        $customer = $this->createCustomer($company, $code.' Customer', [
            'branch_id' => $branch->getKey(),
            'credit_limit' => $creditLimit,
        ]);

        return [$company, $branch, $actor, $customer];
    }

    /** @param list<array{min_weight_kg: float, max_weight_kg: float|null, price_per_kg: float}> $tiers */
    private function createRateCard(Company $company, array $rateCardData, array $tiers): RateCard
    {
        return $this->withTenantReturn($company, function () use ($rateCardData, $tiers): RateCard {
            $rateCard = RateCard::query()->create([
                'name' => 'Standard',
                'currency' => 'AED',
                'base_fee' => $rateCardData['base_fee'] ?? 0,
                'min_charge' => $rateCardData['min_charge'] ?? 0,
                'is_active' => true,
            ]);

            foreach ($tiers as $tier) {
                $rateCard->tiers()->create($tier);
            }

            return $rateCard;
        });
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
