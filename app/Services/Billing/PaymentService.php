<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Webhooks\WebhookDispatchService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Idempotent by the same shape as Phase 5-7's scan/transition/attempt
 * services: check idempotency_key first, then a unique
 * (company_id, idempotency_key) DB constraint with a catch-23000 refetch
 * under a genuine race. "Cannot over-apply" is enforced directly —
 * `amount` must not exceed the invoice's current `balance_due`, checked
 * against the live, always-recomputed balance, never a cached figure.
 */
final readonly class PaymentService
{
    public function __construct(
        private AuditService $audit,
        private WebhookDispatchService $webhooks,
    ) {}

    public function record(
        Invoice $invoice,
        float $amount,
        PaymentMethod $method,
        ?User $actor,
        string $idempotencyKey,
        array $data = [],
        bool $allowClosedInvoice = false,
    ): Payment {
        $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $allowedStatuses = [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid];
        if ($allowClosedInvoice) {
            $allowedStatuses[] = InvoiceStatus::Paid;
        }

        if (! in_array($invoice->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages(['invoice' => 'Payments can only be recorded against an issued, partially paid, or paid invoice.']);
        }

        if (round($amount, 2) === 0.0) {
            throw ValidationException::withMessages(['amount' => 'The payment amount cannot be zero.']);
        }

        $currentPaid = round((float) $invoice->payments()->sum('amount'), 2);
        $balanceDue = round((float) $invoice->total - $currentPaid, 2);

        if ($amount > 0 && round($amount, 2) > round($balanceDue, 2)) {
            throw ValidationException::withMessages(['amount' => sprintf('The payment (%.2f) exceeds the outstanding balance (%.2f).', $amount, $balanceDue)]);
        }

        if ($amount < 0 && round(abs($amount), 2) > round($currentPaid, 2)) {
            throw ValidationException::withMessages(['amount' => sprintf('The refund (%.2f) exceeds paid amount (%.2f).', abs($amount), $currentPaid)]);
        }

        try {
            $payment = DB::transaction(function () use ($invoice, $amount, $method, $actor, $idempotencyKey, $data): Payment {
            $payment = $invoice->payments()->create([
                'amount' => $amount,
                'currency' => $invoice->currency,
                'method' => $method,
                'reference' => $data['reference'] ?? null,
                'received_at' => $data['received_at'] ?? now(),
                    'actor_id' => $actor?->getKey(),
                    'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
            ]);

                $this->recalculate($invoice);

                $this->audit->record('payment.recorded', $actor, $payment, newValues: ['amount' => (string) $payment->amount, 'method' => $method->value]);

                return $payment;
            });
        } catch (QueryException $exception) {
            if ((int) $exception->getCode() !== 23000) {
                throw $exception;
            }

            return Payment::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            $this->webhooks->dispatch(WebhookEventType::InvoicePaid, [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'total' => (string) $invoice->total,
                'currency' => $invoice->currency,
            ], (int) $invoice->company_id);
        }

        return $payment;
    }

    private function recalculate(Invoice $invoice): void
    {
        $amountPaid = round((float) $invoice->payments()->sum('amount'), 2);
        $balanceDue = round((float) $invoice->total - $amountPaid, 2);

        $invoice->forceFill([
            'amount_paid' => $amountPaid,
            'balance_due' => $balanceDue,
            'status' => $balanceDue <= 0 ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid,
        ])->save();
    }
}
