<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreStripeSubscriptionRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Enums\PaymentMethod;
use App\Services\Billing\PaymentService;
use App\Services\Billing\StripeGatewayService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GatewayController extends Controller
{
    public function test(Request $request, AuditService $audit)
    {
        abort_unless((bool) $request->user()?->hasPermission('system-configuration.manage'), 403);
        abort_unless($request->user()?->is_platform_admin, 403);
        $settings = PlatformSetting::current();
        if (! $settings->stripe_secret_key) {
            return back()->with('error', 'Save a Stripe secret key before testing the connection.');
        }
        try {
            // Read-only authentication check. Never create charges or retain balances.
            $response = Http::withBasicAuth($settings->stripe_secret_key, '')
                ->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->get('https://api.stripe.com/v1/balance');
        } catch (ConnectionException) {
            return back()->with('error', 'Stripe could not be reached. Check the server connection and try again.');
        }
        if (! $response->successful() || $response->json('object') !== 'balance') {
            return back()->with('error', 'Stripe authentication failed. Check the secret key and its balance-read permission.');
        }
        $mode = $response->json('livemode') ? 'live' : 'test';
        $audit->record('platform-settings.stripe-tested', $request->user(), $settings, newValues: ['mode' => $mode]);

        return back()->with('success', "Stripe connection verified in {$mode} mode. No payment was created.");
    }

    public function createSubscription(StoreStripeSubscriptionRequest $request, StripeGatewayService $gateway, AuditService $audit)
    {
        abort_unless((bool) $request->user()?->hasPermission('system-configuration.manage'), 403);
        $validated = $request->validated();

        $subscription = $gateway->createSubscription(
            (string) $validated['customer_email'],
            (string) $validated['price_id'],
            (int) ($validated['quantity'] ?? 1),
            $validated['trial_days'] ?? null,
        );

        $audit->record('stripe.subscription.created', $request->user(), null, newValues: [
            'subscription_id' => (string) ($subscription['id'] ?? ''),
            'price_id' => (string) $validated['price_id'],
            'customer' => (string) $validated['customer_email'],
        ]);

        return back()->with('success', "Stripe subscription {$subscription['id']} created.");
    }

    public function cancelSubscription(Request $request, string $subscriptionId, StripeGatewayService $gateway, AuditService $audit)
    {
        abort_unless((bool) $request->user()?->hasPermission('system-configuration.manage'), 403);
        $subscription = $gateway->cancelSubscription($subscriptionId);

        $audit->record('stripe.subscription.cancelled', $request->user(), null, newValues: [
            'subscription_id' => (string) ($subscription['id'] ?? $subscriptionId),
            'status' => (string) ($subscription['status'] ?? ''),
        ]);

        return back()->with('success', "Stripe subscription {$subscriptionId} cancelled.");
    }

    public function webhook(
        Request $request,
        StripeGatewayService $gateway,
        PaymentService $payments,
        AuditService $audit,
        TenantContext $tenantContext,
    ): JsonResponse {
        $payload = $this->decodeWebhookPayload($request);
        if ($payload === null) {
            return response()->json(['received' => false], 400);
        }

        $secret = PlatformSetting::current()->stripe_webhook_secret;
        if (! $this->verifyStripeSignature($request->header('Stripe-Signature'), $request->getContent(), $secret)) {
            return response()->json(['received' => false], 400);
        }

        $eventType = (string) ($payload['type'] ?? '');
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : null;

        try {
            match ($eventType) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($object, $gateway, $payments, $tenantContext),
                'charge.refunded', 'refund.created' => $this->handleRefundEvent($object, $payments, $tenantContext),
                default => null,
            };
        } catch (Throwable $exception) {
            Log::warning('Stripe webhook handler failed', [
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['received' => false], 400);
        }

        $audit->record('billing.stripe-webhook.received', null, null, newValues: [
            'type' => $eventType,
            'handled' => $object === null ? 'ignored' : 'processed',
        ]);

        return response()->json(['received' => true]);
    }

    private function handleCheckoutCompleted(
        ?array $session,
        StripeGatewayService $gateway,
        PaymentService $payments,
        TenantContext $tenantContext,
    ): void {
        if ($session === null) {
            return;
        }

        $metadata = $session['metadata'] ?? [];
        $companyId = (int) ($metadata['company_id'] ?? 0);
        if ($companyId <= 0) {
            return;
        }

        $invoice = $this->invoiceFromMetadata($metadata, $companyId);
        if ($invoice === null) {
            return;
        }

        $paymentIntentId = $this->extractPaymentIntentId($session['payment_intent'] ?? null);
        if ($paymentIntentId === null) {
            return;
        }

        $amountCents = (int) ($session['amount_total'] ?? 0);
        if ($amountCents <= 0) {
            $intent = $gateway->retrievePaymentIntent($paymentIntentId);
            $amountCents = (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0);
        }
        if ($amountCents <= 0) {
            return;
        }

        $amount = round($amountCents / 100, 2);
        if ($amount <= 0.0) {
            return;
        }

        $actorId = $this->extractActorId($metadata);
        $actor = $actorId === null ? null : User::query()->find($actorId);

        $tenantContext->resolveCompany($companyId);
        try {
            $payments->record(
                $invoice,
                $amount,
                PaymentMethod::Card,
                $actor,
                StripeGatewayService::paymentIntentReference($paymentIntentId),
                ['reference' => StripeGatewayService::paymentIntentReference($paymentIntentId)],
                true,
            );
        } finally {
            $tenantContext->forget();
        }
    }

    private function handleRefundEvent(
        ?array $refund,
        PaymentService $payments,
        TenantContext $tenantContext,
    ): void {
        if ($refund === null) {
            return;
        }

        $paymentIntentId = $this->extractPaymentIntentId($refund['payment_intent'] ?? null);
        if ($paymentIntentId === null) {
            return;
        }

        $reference = StripeGatewayService::paymentIntentReference($paymentIntentId);
        $payment = Payment::query()->where('reference', $reference)->first();
        if ($payment === null || $payment->invoice_id === null) {
            return;
        }

        $amount = round(((int) ($refund['amount'] ?? 0)) / 100, 2);
        if ($amount <= 0.0) {
            return;
        }

        $invoice = Invoice::query()->find($payment->invoice_id);
        if ($invoice === null) {
            return;
        }

        $tenantContext->resolveCompany((int) $invoice->company_id);
        try {
            $payments->record(
                $invoice,
                -$amount,
                PaymentMethod::Card,
                null,
                StripeGatewayService::refundReference((string) $refund['id']),
                [
                    'reference' => StripeGatewayService::refundReference((string) $refund['id']),
                    'notes' => 'Stripe refund webhook',
                    'received_at' => CarbonImmutable::now()->toIso8601String(),
                ],
                true,
            );
        } finally {
            $tenantContext->forget();
        }
    }

    private function invoiceFromMetadata(array $metadata, int $companyId): ?Invoice
    {
        $invoiceId = StripeGatewayService::extractInvoiceIdFromMetadata($metadata);
        if ($invoiceId !== null) {
            $tenantContext = app(TenantContext::class);
            $tenantContext->resolveCompany($companyId);
            try {
                $invoice = Invoice::query()->find($invoiceId);
                if ($invoice !== null) {
                    return $invoice;
                }
            } finally {
                $tenantContext->forget();
            }
        }

        $invoiceNumber = $metadata['invoice_number'] ?? null;
        if (! is_string($invoiceNumber) || $invoiceNumber === '') {
            return null;
        }

        $tenantContext = app(TenantContext::class);
        $tenantContext->resolveCompany($companyId);
        try {
            return Invoice::query()->where('invoice_number', $invoiceNumber)->first();
        } finally {
            $tenantContext->forget();
        }
    }

    private function decodeWebhookPayload(Request $request): ?array
    {
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    }

    private function verifyStripeSignature(?string $signatureHeader, string $payload, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            return false;
        }

        if ($signatureHeader === null || ! str_starts_with($signatureHeader, 't=')) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
            $chunk = explode('=', trim($part), 2);
            if (count($chunk) !== 2) {
                continue;
            }

            [$key, $value] = $chunk;
            if ($key === 't') {
                $timestamp = (int) $value;
            }

            if ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function extractPaymentIntentId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && isset($value['id']) && is_string($value['id'])) {
            return $value['id'];
        }

        return null;
    }

    private function extractActorId(array $metadata): ?int
    {
        if (! isset($metadata['actor_id']) || ! is_string($metadata['actor_id'])) {
            return null;
        }

        return ctype_digit($metadata['actor_id']) ? (int) $metadata['actor_id'] : null;
    }
}
