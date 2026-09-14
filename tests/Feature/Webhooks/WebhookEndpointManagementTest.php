<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Company;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class WebhookEndpointManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_create_a_webhook_endpoint_and_the_secret_is_never_returned_in_the_listing(): void
    {
        $company = $this->createCompany('WEA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['webhooks.view', 'webhooks.manage']);

        $this->actingAs($actor)
            ->post('/settings/webhook-endpoints', [
                'url' => 'https://example.test/hooks/cargoflow',
                'event_types' => ['shipment.status_changed'],
            ])
            ->assertRedirect();

        $endpoint = $this->withTenantReturn($company, fn () => WebhookEndpoint::query()->firstOrFail());
        self::assertNotEmpty($endpoint->secret);

        $response = $this->actingAs($actor)->get('/settings/webhook-endpoints');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('endpoints', 1)->missing('endpoints.0.secret'));
    }

    public function test_a_user_without_permission_cannot_create_a_webhook_endpoint(): void
    {
        $company = $this->createCompany('WEB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)
            ->post('/settings/webhook-endpoints', [
                'url' => 'https://example.test/hooks/cargoflow',
                'event_types' => ['shipment.status_changed'],
            ])
            ->assertForbidden();
    }

    public function test_a_non_https_url_is_rejected(): void
    {
        $company = $this->createCompany('WEC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['webhooks.view', 'webhooks.manage']);

        $this->actingAs($actor)
            ->post('/settings/webhook-endpoints', [
                'url' => 'http://example.test/hooks/cargoflow',
                'event_types' => ['shipment.status_changed'],
            ])
            ->assertSessionHasErrors('url');
    }

    public function test_a_company_cannot_update_or_delete_another_companys_webhook_endpoint(): void
    {
        $companyA = $this->createCompany('WED');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actorA = $this->createUser($companyA, $branchA);
        $this->grantPermissions($actorA, ['webhooks.view', 'webhooks.manage']);

        $companyB = $this->createCompany('WEE');
        $endpointB = $this->withTenantReturn($companyB, fn () => WebhookEndpoint::query()->create([
            'url' => 'https://example.test/hooks/other',
            'secret' => 'secret',
            'event_types' => ['*'],
            'is_active' => true,
        ]));

        $this->actingAs($actorA)
            ->patch("/settings/webhook-endpoints/{$endpointB->getKey()}", [
                'url' => 'https://example.test/hooks/hijacked',
                'event_types' => ['*'],
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->actingAs($actorA)
            ->delete("/settings/webhook-endpoints/{$endpointB->getKey()}")
            ->assertNotFound();
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
