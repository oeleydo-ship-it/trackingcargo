<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Jobs\SendWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Fans one domain event out to every active endpoint subscribed to it,
 * writing one WebhookDelivery row (idempotency key = a fresh UUID, since
 * each fan-out target is its own delivery attempt) and queuing one
 * SendWebhookJob per row on the `webhooks` queue — always called *after*
 * the triggering DB::transaction() has committed (Architecture: "Webhooks
 * ... are queued after commit"), so a rolled-back change never reaches
 * here and therefore never sends anything.
 */
final readonly class WebhookDispatchService
{
    public function __construct(private TenantContext $tenantContext) {}

    /** @param array<string, mixed> $payload */
    public function dispatch(WebhookEventType $eventType, array $payload, int $companyId): void
    {
        $wasResolved = $this->tenantContext->isResolved();
        if (! $wasResolved) {
            $this->tenantContext->resolveCompany($companyId);
        }

        try {
            WebhookEndpoint::query()
                ->where('is_active', true)
                ->get()
                ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($eventType->value))
                ->each(function (WebhookEndpoint $endpoint) use ($eventType, $payload, $companyId): void {
                    $delivery = $endpoint->deliveries()->create([
                        'event_type' => $eventType->value,
                        'payload' => $payload,
                        'idempotency_key' => (string) Str::uuid(),
                        'status' => WebhookDeliveryStatus::Pending,
                    ]);

                    SendWebhookJob::dispatch($companyId, $delivery->getKey())->onQueue('webhooks');
                });
        } finally {
            if (! $wasResolved) {
                $this->tenantContext->forget();
            }
        }
    }
}
