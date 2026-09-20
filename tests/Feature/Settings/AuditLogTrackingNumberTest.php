<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Company;
use App\Services\Audit\AuditService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class AuditLogTrackingNumberTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function asTenant(Company $company, Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }

    public function test_shipment_entries_show_the_shipments_tracking_number(): void
    {
        $company = $this->createCompany('ATA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['audit-logs.view']);

        $shipment = $this->createShipment($company, $branch, ['tracking_number' => 'ATA-DXB-11112222']);

        $this->asTenant($company, function () use ($actor, $shipment): void {
            app(AuditService::class)->record('shipment.status-changed', $actor, $shipment);
            app(AuditService::class)->record('shipment.updated', $actor, $shipment->packages()->firstOrFail());
            app(AuditService::class)->record('test.no-shipment', $actor);
        });

        $this->actingAs($actor)->get('/settings/audit-log')->assertInertia(fn ($page) => $page
            ->where('logs.data.0.tracking_numbers', [])
            ->where('logs.data.1.tracking_numbers', ['ATA-DXB-11112222'])
            ->where('logs.data.2.tracking_numbers', ['ATA-DXB-11112222']));
    }

    public function test_a_deleted_shipment_still_shows_its_tracking_number(): void
    {
        $company = $this->createCompany('ATB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['audit-logs.view']);

        $shipment = $this->createShipment($company, $branch, ['tracking_number' => 'ATB-DXB-33334444']);

        $this->asTenant($company, function () use ($actor, $shipment): void {
            app(AuditService::class)->record('shipment.deleted', $actor, $shipment);
            $shipment->delete();
        });

        $this->actingAs($actor)->get('/settings/audit-log')->assertInertia(fn ($page) => $page
            ->where('logs.data.0.tracking_numbers', ['ATB-DXB-33334444']));
    }

    public function test_batch_entries_list_the_tracking_numbers_they_touched(): void
    {
        $company = $this->createCompany('ATC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['audit-logs.view']);

        app(AuditService::class)->record('batch.shipments-added', $actor, newValues: ['tracking_numbers' => ['A-1', 'A-2']]);
        app(AuditService::class)->record('batch.shipments-removed', $actor, oldValues: ['tracking_numbers' => ['A-3']]);
        app(AuditService::class)->record('batch.bulk-transitioned', $actor, newValues: ['to_status' => 'x', 'applied' => ['A-4'], 'skipped' => ['A-5']]);

        $this->actingAs($actor)->get('/settings/audit-log')->assertInertia(fn ($page) => $page
            ->where('logs.data.0.tracking_numbers', ['A-4'])
            ->where('logs.data.1.tracking_numbers', ['A-3'])
            ->where('logs.data.2.tracking_numbers', ['A-1', 'A-2']));
    }
}
