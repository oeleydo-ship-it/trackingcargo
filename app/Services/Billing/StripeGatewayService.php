<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Http;

final readonly class StripeGatewayService
{
    private const string BASE_URL = 'https://api.stripe.com/v1';

    public function createCheckoutSession(
        string $customerEmail,
        string $invoiceNumber,
        int $invoiceId,
        string $currency,
        string $amount,
        int $companyId,
        int $actorId,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $metadata = [
            'company_id' => (string) $companyId,
            'actor_id' => (string) $actorId,
            'invoice_number' => $invoiceNumber,
            'invoice_id' => (string) $invoiceId,
        ];

        return $this->post('/checkout/sessions', [
            'mode' => 'payment',
            'line_items' => [
                [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'unit_amount' => $this->toCents((float) $amount),
                        'product_data' => [
                            'name' => "Invoice {$invoiceNumber}",
                        ],
                    ],
                ],
            ],
            'payment_intent_data' => [
                'metadata' => $metadata,
            ],
            'metadata' => $metadata,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'allow_promotion_codes' => false,
            'customer_email' => $customerEmail,
        ]);
    }

    public function createSubscription(string $customerEmail, string $priceId, int $quantity, ?int $trialDays = null): array
    {
        $customer = $this->ensureCustomer($customerEmail);

        $payload = [
            'customer' => $customer['id'],
            'items' => [
                [
                    'price' => $priceId,
                    'quantity' => $quantity,
                ],
            ],
            'description' => "CargoFlow subscription to {$priceId}",
            'payment_behavior' => 'default_incomplete',
        ];

        if ($trialDays !== null) {
            $payload['trial_period_days'] = $trialDays;
        }

        return $this->post('/subscriptions', $payload);
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->delete("/subscriptions/{$subscriptionId}", ['prorate' => true]);
    }

    public function refundByPaymentIntent(string $paymentIntentId, ?int $amountCents = null): array
    {
        $payload = ['payment_intent' => $paymentIntentId];

        if ($amountCents !== null) {
            $payload['amount'] = $amountCents;
        }

        return $this->post('/refunds', $payload);
    }

    public function listSubscriptions(int $limit = 20): array
    {
        $response = $this->get('/subscriptions', [
            'status' => 'all',
            'limit' => max(1, min($limit, 100)),
        ]);

        $items = $response['data'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        return array_map(function (array $subscription) {
            $item = $subscription['items']['data'][0] ?? [];

            return [
                'id' => (string) ($subscription['id'] ?? ''),
                'status' => (string) ($subscription['status'] ?? ''),
                'customer' => (string) ($subscription['customer'] ?? ''),
                'created' => (int) ($subscription['created'] ?? 0),
                'current_period_end' => (int) ($subscription['current_period_end'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price_id' => (string) ($item['price']['id'] ?? ''),
                'price_currency' => (string) ($item['price']['currency'] ?? ''),
                'unit_amount' => $item['price']['unit_amount'] ?? null,
            ];
        }, $items);
    }

    public function listCustomers(string $email, int $limit = 1): array
    {
        $response = $this->get('/customers', ['email' => $email, 'limit' => $limit]);

        return $response['data'] ?? [];
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        $paymentIntent = $this->get("/payment_intents/{$paymentIntentId}");

        return $paymentIntent;
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $session = $this->get("/checkout/sessions/{$sessionId}");

        return $session;
    }

    public function get(string $path, array $payload = []): array
    {
        return $this->request('get', $path, $payload);
    }

    public function post(string $path, array $payload): array
    {
        return $this->request('post', $path, $payload, true);
    }

    public function delete(string $path, array $payload = []): array
    {
        return $this->request('delete', $path, $payload, true);
    }

    public static function paymentIntentReference(string $paymentIntentId): string
    {
        return "stripe_payment_intent:{$paymentIntentId}";
    }

    public static function refundReference(string $refundId): string
    {
        return "stripe_refund:{$refundId}";
    }

    public static function extractPaymentIntentFromReference(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        if (! str_starts_with($reference, 'stripe_payment_intent:')) {
            return null;
        }

        return substr($reference, 22);
    }

    public static function extractInvoiceIdFromMetadata(?array $metadata): ?int
    {
        if ($metadata === null || ! isset($metadata['invoice_id'])) {
            return null;
        }

        $id = (string) $metadata['invoice_id'];
        return ctype_digit($id) ? (int) $id : null;
    }

    private function ensureCustomer(string $email): array
    {
        $existingCustomers = $this->listCustomers($email);
        if ($existingCustomers !== [] && isset($existingCustomers[0]['id'])) {
            return $existingCustomers[0];
        }

        return $this->post('/customers', ['email' => $email, 'description' => 'CargoFlow customer']);
    }

    private function request(string $method, string $path, array $payload = [], bool $form = false): array
    {
        $secret = PlatformSetting::current()->stripe_secret_key;
        if ($secret === null || $secret === '') {
            throw ValidationException::withMessages(['stripe' => 'Stripe secret key is not configured.']);
        }

        $client = $this->client($secret);
        $url = self::BASE_URL.$path;

        $response = match ($method) {
            'get' => $form ? $client->get($url, $payload) : $client->get($url),
            'delete' => $client->asForm()->delete($url, $payload),
            default => $client->asForm()->post($url, $payload),
        };

        if ($response->failed()) {
            $message = (string) ($response->json('error.message') ?? "Stripe request to {$path} failed.");
            $requestId = (string) ($response->json('request_id') ?? 'unknown');
            throw ValidationException::withMessages(['stripe' => "{$message} (request: {$requestId})"]);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['stripe' => 'Stripe returned a malformed response.']);
        }

        return $payload;
    }

    private function client(string $secret): PendingRequest
    {
        return Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->withoutRedirecting();
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
