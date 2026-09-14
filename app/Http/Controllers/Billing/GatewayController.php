<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\RefundPaymentRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\PaymentService;
use App\Services\Billing\StripeGatewayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class GatewayController extends Controller
{
    public function create(Customer $customer, Invoice $invoice, Request $request, StripeGatewayService $gateway): RedirectResponse
    {
        abort_unless($invoice->customer_id === $customer->getKey(), 404);
        if (! $request->user()?->can('recordPayment', $invoice)) {
            abort(403);
        }

        if (! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
            return back()->with('error', 'Only issued or partially paid invoices can be checked out online.');
        }

        if ((float) $invoice->balance_due <= 0.0) {
            return back()->with('error', 'This invoice has no outstanding balance.');
        }

        $successUrl = route('crm.customers.invoices.show', [$customer, $invoice]) . '?checkout_status=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('crm.customers.invoices.show', [$customer, $invoice]) . '?checkout_status=cancelled';

        $actor = $request->user();
        $session = $gateway->createCheckoutSession(
            (string) ($customer->email ?? ''),
            $invoice->invoice_number,
            (int) $invoice->getKey(),
            $invoice->currency,
            (string) $invoice->balance_due,
            (int) $invoice->company_id,
            (int) $actor?->getKey(),
            $successUrl,
            $cancelUrl,
        );

        $url = $session['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return back()->with('error', 'Stripe checkout session could not be created.');
        }

        return redirect()->away($url);
    }

    public function refund(
        RefundPaymentRequest $request,
        Customer $customer,
        Invoice $invoice,
        Payment $payment,
        StripeGatewayService $gateway,
        PaymentService $payments,
    ): RedirectResponse {
        abort_unless($invoice->customer_id === $customer->getKey(), 404);
        abort_unless($payment->invoice_id === $invoice->getKey(), 404);
        abort_unless($payment->method === PaymentMethod::Card, 422, 'Only card payments can be refunded through Stripe.');

        $reference = (string) $payment->reference;
        $paymentIntentId = StripeGatewayService::extractPaymentIntentFromReference($reference);
        if ($paymentIntentId === null) {
            return back()->with('error', 'This payment does not include a Stripe payment intent reference.');
        }

        $requestedAmount = $request->validated('amount') !== null
            ? (float) $request->validated('amount')
            : null;

        $refund = $gateway->refundByPaymentIntent($paymentIntentId, $requestedAmount === null ? null : (int) round($requestedAmount * 100));
        $refundAmount = (float) ((int) ($refund['amount'] ?? 0) / 100);
        if ($refundAmount <= 0.0) {
            return back()->with('error', 'Stripe returned an empty refund amount.');
        }

        $requestedAmount ??= $refundAmount;
        $maxRefund = (float) $payment->amount;
        if ($requestedAmount > $maxRefund) {
            return back()->with('error', sprintf('This payment is only %.2f, so it cannot be refunded for %.2f.', $maxRefund, $requestedAmount));
        }

        $payments->record(
            $invoice,
            -min($refundAmount, $requestedAmount),
            PaymentMethod::Card,
            $request->user(),
            (string) ('stripe_refund:'.($refund['id'] ?? uniqid('stripe-refund-', true))),
            [
                'reference' => StripeGatewayService::refundReference((string) ($refund['id'] ?? '')),
                'notes' => trim(sprintf('Stripe refund %s', (string) ($refund['id'] ?? '')) . (
                    $request->filled('reason') ? " · Reason: {$request->validated('reason')}" : ''
                )),
                'received_at' => now(),
            ],
            true,
        );

        return back()->with('success', 'Stripe refund request was submitted and recorded.');
    }
}
