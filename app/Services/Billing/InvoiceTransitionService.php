<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceIssuedNotification;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class InvoiceTransitionService
{
    public function __construct(
        private AuditService $audit,
        private CustomerBalanceService $balances,
    ) {}

    public function transition(Invoice $invoice, InvoiceStatus $to, User $actor): Invoice
    {
        $from = $invoice->status;

        if (! InvoiceTransitionMap::isAllowed($from, $to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition an invoice from \"{$from->label()}\" to \"{$to->label()}\".",
            ]);
        }

        if ($to === InvoiceStatus::Issued) {
            $this->assertWithinCreditLimit($invoice);
        }

        if ($to === InvoiceStatus::Void && (float) $invoice->amount_paid > 0) {
            throw ValidationException::withMessages(['status' => 'An invoice with payments already applied cannot be voided.']);
        }

        $invoice = DB::transaction(function () use ($invoice, $from, $to, $actor): Invoice {
            $invoice->forceFill([
                'status' => $to,
                'issue_date' => $to === InvoiceStatus::Issued ? now()->toDateString() : $invoice->issue_date,
            ])->save();

            $this->audit->record('invoice.status-changed', $actor, $invoice, oldValues: ['status' => $from->value], newValues: ['status' => $to->value]);

            return $invoice;
        });

        if ($to === InvoiceStatus::Issued) {
            $invoice->customer->portalUser?->notify(new InvoiceIssuedNotification($invoice));
        }

        return $invoice;
    }

    private function assertWithinCreditLimit(Invoice $invoice): void
    {
        $customer = $invoice->customer;

        if ($customer->credit_limit === null) {
            return;
        }

        $projectedBalance = $this->balances->outstandingBalance($customer) + (float) $invoice->total;

        if ($projectedBalance > (float) $customer->credit_limit) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Issuing this invoice would put %s at %s %.2f, over their %s %.2f credit limit.',
                    $customer->name,
                    $invoice->currency,
                    $projectedBalance,
                    $invoice->currency,
                    (float) $customer->credit_limit,
                ),
            ]);
        }
    }
}
