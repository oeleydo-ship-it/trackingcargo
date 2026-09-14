<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RateCard;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Numbering\NumberSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class InvoiceService
{
    public function __construct(
        private AuditService $audit,
        private NumberSequenceService $sequences,
        private RateCalculatorService $calculator,
    ) {}

    public function create(Customer $customer, array $data, User $actor): Invoice
    {
        return DB::transaction(function () use ($customer, $data, $actor): Invoice {
            $invoice = $customer->invoices()->create([
                'branch_id' => $customer->branch_id,
                'invoice_number' => $this->allocateNumber($customer),
                'status' => InvoiceStatus::Draft,
                'currency' => $data['currency'],
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->record('invoice.created', $actor, $invoice, newValues: ['invoice_number' => $invoice->invoice_number]);

            return $invoice;
        });
    }

    public function addShipmentItem(Invoice $invoice, Shipment $shipment, RateCard $rateCard, User $actor): InvoiceItem
    {
        $this->assertDraft($invoice);

        $result = $this->calculator->calculate($rateCard, (float) $shipment->chargeable_weight_kg);

        return $this->addItem($invoice, [
            'shipment_id' => $shipment->getKey(),
            'description' => "Freight charges — {$shipment->tracking_number}",
            'quantity' => (float) $shipment->chargeable_weight_kg,
            'unit_price' => $shipment->chargeable_weight_kg > 0 ? round($result['amount'] / (float) $shipment->chargeable_weight_kg, 2) : $result['amount'],
            'amount' => $result['amount'],
        ], $actor);
    }

    public function addManualItem(Invoice $invoice, array $data, User $actor): InvoiceItem
    {
        $this->assertDraft($invoice);

        $quantity = (float) $data['quantity'];
        $unitPrice = (float) $data['unit_price'];

        return $this->addItem($invoice, [
            'shipment_id' => $data['shipment_id'] ?? null,
            'description' => $data['description'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => round($quantity * $unitPrice, 2),
        ], $actor);
    }

    public function removeItem(Invoice $invoice, InvoiceItem $item, User $actor): void
    {
        $this->assertDraft($invoice);

        DB::transaction(function () use ($invoice, $item, $actor): void {
            $item->delete();
            $this->recalculate($invoice);

            $this->audit->record('invoice-item.removed', $actor, $invoice, oldValues: ['item_id' => $item->getKey(), 'amount' => (string) $item->amount]);
        });
    }

    /** @param array{shipment_id: int|null, description: string, quantity: float, unit_price: float, amount: float} $data */
    private function addItem(Invoice $invoice, array $data, User $actor): InvoiceItem
    {
        return DB::transaction(function () use ($invoice, $data, $actor): InvoiceItem {
            $item = $invoice->items()->create([
                'shipment_id' => $data['shipment_id'],
                'description' => $data['description'],
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'],
                'amount' => $data['amount'],
            ]);

            $this->recalculate($invoice);

            $this->audit->record('invoice-item.added', $actor, $item, newValues: ['description' => $item->description, 'amount' => (string) $item->amount]);

            return $item;
        });
    }

    private function recalculate(Invoice $invoice): void
    {
        $subtotal = round((float) $invoice->items()->sum('amount'), 2);
        $total = round($subtotal + (float) $invoice->tax_amount, 2);

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'total' => $total,
            'balance_due' => round($total - (float) $invoice->amount_paid, 2),
        ])->save();
    }

    private function assertDraft(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages(['invoice' => 'Line items can only be changed while the invoice is a draft.']);
        }
    }

    private function allocateNumber(Customer $customer): string
    {
        $sequence = $this->sequences->next('invoice', $customer->branch_id !== null ? (int) $customer->branch_id : null);

        return sprintf('%s-INV-%06d', $customer->customer_number, $sequence);
    }
}
