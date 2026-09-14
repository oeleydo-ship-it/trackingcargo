<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_without_permission_cannot_view_the_audit_log(): void
    {
        $company = $this->createCompany('ALA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)->get('/settings/audit-log')->assertForbidden();
    }

    public function test_a_user_with_permission_sees_only_their_own_companys_entries(): void
    {
        $companyA = $this->createCompany('ALB');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actorA = $this->createUser($companyA, $branchA);
        $this->grantPermissions($actorA, ['audit-logs.view']);

        $companyB = $this->createCompany('ALC');
        $branchB = $this->createBranch($companyB, 'MNL');
        $actorB = $this->createUser($companyB, $branchB);

        app(AuditService::class)->record('test.action-a', $actorA);
        app(AuditService::class)->record('test.action-b', $actorB);

        $response = $this->actingAs($actorA)->get('/settings/audit-log');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'test.action-a'));
    }

    public function test_the_action_filter_narrows_results(): void
    {
        $company = $this->createCompany('ALD');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['audit-logs.view']);

        app(AuditService::class)->record('invoice.status-changed', $actor);
        app(AuditService::class)->record('shipment.status-changed', $actor);

        $response = $this->actingAs($actor)->get('/settings/audit-log?action=invoice');
        $response->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'invoice.status-changed'));
    }
}
