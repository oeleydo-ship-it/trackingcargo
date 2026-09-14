<?php

namespace Tests\Feature\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\SendWebhookJob;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class WebhookRetryTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_failed_delivery_can_be_queued_once_by_its_company_manager(): void
    {
        Queue::fake();
        $company = $this->createCompany('RETRY');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['webhooks.manage']);
        app(TenantContext::class)->resolveCompany($company->id);
        $endpoint = WebhookEndpoint::query()->create(['url' => 'https://example.test/hooks', 'secret' => Str::random(64), 'event_types' => ['*'], 'is_active' => true]);
        $delivery = $endpoint->deliveries()->create(['event_type' => 'shipment.status_changed', 'payload' => ['test' => true], 'idempotency_key' => (string) Str::uuid(), 'status' => WebhookDeliveryStatus::Failed]);
        $key = $delivery->idempotency_key;
        app(TenantContext::class)->forget();

        $other = $this->createUser($this->createCompany('FOREIGN'));
        $this->grantPermissions($other, ['webhooks.manage']);
        $this->actingAs($other)->post('/settings/webhook-deliveries/'.$delivery->id.'/retry')->assertNotFound();
        $viewer = $this->createUser($company);
        $this->actingAs($viewer)->post('/settings/webhook-deliveries/'.$delivery->id.'/retry')->assertForbidden();
        $this->actingAs($actor)->post('/settings/webhook-deliveries/'.$delivery->id.'/retry')->assertSessionHasNoErrors();
        Queue::assertPushed(SendWebhookJob::class, 1);
        $this->post('/settings/webhook-deliveries/'.$delivery->id.'/retry')->assertSessionHasErrors('delivery');
        Queue::assertPushed(SendWebhookJob::class, 1);
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'status' => 'pending', 'idempotency_key' => $key]);
    }
}
