<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\WebhookDeliveryFailedNotification;
use App\Services\Audit\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Signs and sends one webhook delivery, retrying with backoff on failure —
 * see docs/WEBHOOKS.md for the exact signature algorithm a receiver must
 * reproduce. Carries `companyId` explicitly (Architecture: "Background jobs
 * must carry the company identifier and restore tenant context before
 * loading tenant models") and resolves TenantContext at the top of handle(),
 * since a queue worker process starts with no tenant context at all.
 */
final class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        private readonly int $companyId,
        private readonly int $webhookDeliveryId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function handle(TenantContext $tenantContext, AuditService $audit): void
    {
        $tenantContext->resolveCompany($this->companyId);

        try {
            $delivery = WebhookDelivery::query()->with('endpoint')->findOrFail($this->webhookDeliveryId);
            $endpoint = $delivery->endpoint;
            if ($delivery->status === WebhookDeliveryStatus::Succeeded) {
                return;
            }
            if ($endpoint === null || ! $endpoint->is_active) {
                $delivery->forceFill(['status' => WebhookDeliveryStatus::Failed])->save();

                return;
            }

            $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
            $signature = hash_hmac('sha256', $body, $endpoint->secret);

            $delivery->forceFill([
                'attempts' => $delivery->attempts + 1,
                'last_attempted_at' => now(),
            ])->save();

            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-CargoFlow-Event' => $delivery->event_type,
                    'X-CargoFlow-Signature' => "sha256={$signature}",
                    'X-CargoFlow-Delivery' => $delivery->idempotency_key,
                ])
                ->timeout(10)
                ->post($endpoint->url);

            $delivery->forceFill([
                'response_status' => $response->status(),
                'response_excerpt' => mb_substr($response->body(), 0, 500),
            ])->save();

            if ($response->failed()) {
                $response->throw();
            }

            $delivery->forceFill(['status' => WebhookDeliveryStatus::Succeeded])->save();
        } finally {
            $tenantContext->forget();
        }
    }

    public function failed(Throwable $exception): void
    {
        app(TenantContext::class)->resolveCompany($this->companyId);

        try {
            $delivery = WebhookDelivery::query()->find($this->webhookDeliveryId);

            if ($delivery === null) {
                return;
            }

            $delivery->forceFill(['status' => WebhookDeliveryStatus::Failed])->save();

            app(AuditService::class)->record('webhook.delivery-failed', null, $delivery, newValues: [
                'event_type' => $delivery->event_type,
                'error' => $exception->getMessage(),
            ]);

            $company = Company::query()->find($this->companyId);

            User::query()
                ->whereHas('roles', fn ($query) => $query->where('slug', 'company-admin'))
                ->get()
                ->each(fn (User $admin) => $admin->notify(new WebhookDeliveryFailedNotification($delivery, $company?->name ?? 'your company')));
        } finally {
            app(TenantContext::class)->forget();
        }
    }
}
