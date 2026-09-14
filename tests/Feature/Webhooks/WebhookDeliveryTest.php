<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\ShipmentStatusRole;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\SendWebhookJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Shipments\ShipmentTransitionService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Proves the "signed retried webhooks" deliverable end to end: a domain
 * event (a shipment status change) fans out to a subscribed endpoint, the
 * request carries an HMAC-SHA256 signature a receiver can independently
 * verify (see docs/WEBHOOKS.md for the documented algorithm this test
 * reproduces), a failed delivery retries with backoff and is recorded, and
 * a rejected/guarded domain action never sends anything at all.
 */
final class WebhookDeliveryTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_shipment_status_change_sends_a_correctly_signed_webhook(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WDA');
        $endpoint = $this->createEndpoint($company, 'https://example.test/hooks/cargoflow', ['shipment.status_changed']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'received']);

        Http::fake(['example.test/*' => Http::response('ok', 200)]);

        $this->withTenant($company, function () use ($shipment, $actor): void {
            app(ShipmentTransitionService::class)->transitionToRole($shipment, ShipmentStatusRole::InTransit, $actor);
        });

        Http::assertSent(function ($request) use ($endpoint): bool {
            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->body(), $endpoint->secret);

            return $request->url() === $endpoint->url
                && $request->hasHeader('X-CargoFlow-Signature', $expectedSignature)
                && $request->header('X-CargoFlow-Event')[0] === 'shipment.status_changed';
        });

        $this->withTenant($company, function () use ($endpoint): void {
            $delivery = WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->getKey())->firstOrFail();
            self::assertSame('succeeded', $delivery->status->value);
            self::assertSame(200, $delivery->response_status);
        });
    }

    public function test_an_endpoint_not_subscribed_to_the_event_receives_nothing(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WDB');
        $this->createEndpoint($company, 'https://example.test/hooks/other', ['invoice.paid']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'received']);

        Http::fake();

        $this->withTenant($company, function () use ($shipment, $actor): void {
            app(ShipmentTransitionService::class)->transitionToRole($shipment, ShipmentStatusRole::InTransit, $actor);
        });

        Http::assertNothingSent();
    }

    public function test_a_rejected_transition_sends_no_webhook_at_all(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('WDC');
        $this->createEndpoint($company, 'https://example.test/hooks/cargoflow', ['*']);
        $shipment = $this->createShipment($company, $branch, ['status' => 'delivered']);

        Http::fake();

        $this->withTenant($company, function () use ($shipment, $actor): void {
            try {
                app(ShipmentTransitionService::class)->transitionToRole($shipment, ShipmentStatusRole::InTransit, $actor);
                self::fail('Expected the invalid transition to be rejected.');
            } catch (ValidationException) {
                // expected — delivered -> in_transit is not in ShipmentTransitionMap
            }
        });

        Http::assertNothingSent();
    }

    public function test_a_failing_delivery_records_the_attempt_with_the_response_status(): void
    {
        [$company, $branch] = $this->setUpTenant('WDD');
        $endpoint = $this->createEndpoint($company, 'https://example.test/hooks/flaky', ['shipment.status_changed']);
        $deliveryId = $this->createPendingDelivery($company, $endpoint);

        Http::fake(['example.test/*' => Http::response('server error', 500)]);

        $job = new SendWebhookJob((int) $company->getKey(), $deliveryId);

        try {
            app()->call([$job, 'handle']);
            self::fail('Expected the 500 response to raise a RequestException.');
        } catch (RequestException) {
            // expected — handle() calls $response->throw() on a failed response so the
            // queue's own retry/backoff mechanism takes over on a real worker.
        }

        $this->withTenant($company, function () use ($deliveryId): void {
            $delivery = WebhookDelivery::query()->findOrFail($deliveryId);
            self::assertSame(1, $delivery->attempts);
            self::assertSame(500, $delivery->response_status);
            self::assertSame(WebhookDeliveryStatus::Pending, $delivery->status);
        });
    }

    public function test_a_permanently_failed_delivery_is_marked_failed_and_notifies_company_admins(): void
    {
        [$company, $branch] = $this->setUpTenant('WDE');
        $admin = $this->createUser($company, $branch);
        $this->withTenant($company, function () use ($admin, $company): void {
            $role = Role::query()->create(['name' => 'Company Admin', 'slug' => 'company-admin', 'is_system' => true]);
            $admin->roles()->attach($role->getKey(), ['company_id' => $company->getKey()]);
        });

        $endpoint = $this->createEndpoint($company, 'https://example.test/hooks/flaky', ['shipment.status_changed']);
        $deliveryId = $this->createPendingDelivery($company, $endpoint);

        $job = new SendWebhookJob((int) $company->getKey(), $deliveryId);
        $job->failed(new RuntimeException('simulated permanent failure'));

        $this->withTenant($company, function () use ($deliveryId, $admin): void {
            $delivery = WebhookDelivery::query()->findOrFail($deliveryId);
            self::assertSame(WebhookDeliveryStatus::Failed, $delivery->status);
            self::assertSame(1, $admin->notifications()->count());
        });
    }

    private function createPendingDelivery(Company $company, WebhookEndpoint $endpoint): int
    {
        return $this->withTenantReturn($company, fn () => $endpoint->deliveries()->create([
            'event_type' => 'shipment.status_changed',
            'payload' => ['shipment_id' => 1],
            'idempotency_key' => (string) Str::uuid(),
            'status' => WebhookDeliveryStatus::Pending,
        ])->getKey());
    }

    /** @return array{0: Company, 1: Branch, 2: User} */
    private function setUpTenant(string $code): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.view', 'tracking.update', 'webhooks.view', 'webhooks.manage']);

        return [$company, $branch, $actor];
    }

    /** @param list<string> $eventTypes */
    private function createEndpoint(Company $company, string $url, array $eventTypes): WebhookEndpoint
    {
        return $this->withTenantReturn($company, fn () => WebhookEndpoint::query()->create([
            'url' => $url,
            'secret' => 'test-secret-'.uniqid(),
            'event_types' => $eventTypes,
            'is_active' => true,
        ]));
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
