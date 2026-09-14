<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class BranchManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_branch_with_assigned_users_cannot_be_deleted(): void
    {
        $company = $this->createCompany('KEEP');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['branches.manage']);
        $this->actingAs($actor)->delete('/settings/branches/'.$branch->id)->assertSessionHasErrors('branch');
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'deleted_at' => null]);
    }

    public function test_a_user_with_permission_can_list_and_create_branches(): void
    {
        $company = $this->createCompany('BRA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['branches.view', 'branches.manage']);

        $this->actingAs($actor)
            ->get('/settings/branches')
            ->assertOk();

        $this->actingAs($actor)
            ->post('/settings/branches', [
                'name' => 'New Branch',
                'code' => 'NEW',
                'tracking_prefix' => 'NEW',
                'country_code' => 'AE',
                'city' => 'Dubai',
                'timezone' => 'Asia/Dubai',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('branches', ['code' => 'NEW', 'company_id' => $company->getKey()]);
    }

    public function test_a_user_without_permission_cannot_create_a_branch(): void
    {
        $company = $this->createCompany('BRB');
        $actor = $this->createUser($company);

        $this->actingAs($actor)
            ->post('/settings/branches', [
                'name' => 'New Branch',
                'code' => 'NEW',
                'tracking_prefix' => 'NEW',
                'country_code' => 'AE',
                'city' => 'Dubai',
                'timezone' => 'Asia/Dubai',
            ])
            ->assertForbidden();
    }

    public function test_a_company_cannot_view_or_modify_another_companys_branch(): void
    {
        $companyA = $this->createCompany('BRC');
        $companyB = $this->createCompany('BRD');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['branches.view', 'branches.manage']);
        $branchB = $this->createBranch($companyB, 'BX1');

        $this->actingAs($actorA)
            ->patch("/settings/branches/{$branchB->getKey()}", [
                'name' => 'Hijacked',
                'code' => 'BX1',
                'tracking_prefix' => 'BX1',
                'country_code' => 'AE',
                'city' => 'Dubai',
                'timezone' => 'Asia/Dubai',
            ])
            ->assertNotFound();

        $this->actingAs($actorA)
            ->delete("/settings/branches/{$branchB->getKey()}")
            ->assertNotFound();

        $this->assertDatabaseHas('branches', ['id' => $branchB->getKey(), 'name' => 'BX1 Branch']);
    }

    public function test_branch_scoped_staff_cannot_view_a_sibling_branch_without_company_wide_access(): void
    {
        $company = $this->createCompany('BSA');
        $homeBranch = $this->createBranch($company, 'HB1');
        $otherBranch = $this->createBranch($company, 'HB2');
        $actor = $this->createUser($company, $homeBranch);
        $this->grantPermissions($actor, ['branches.view']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            self::assertFalse($actor->can('view', $otherBranch));
            self::assertTrue($actor->can('view', $homeBranch));
        } finally {
            $context->forget();
        }
    }

    public function test_company_wide_staff_can_view_any_branch_in_their_company(): void
    {
        $company = $this->createCompany('BSB');
        $branch = $this->createBranch($company, 'HB3');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['branches.view']);

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            self::assertTrue($actor->can('view', $branch));
        } finally {
            $context->forget();
        }
    }

    public function test_branch_listing_never_includes_another_companys_branches(): void
    {
        $companyA = $this->createCompany('BRE');
        $companyB = $this->createCompany('BRF');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['branches.view']);
        $this->createBranch($companyA, 'AAX');
        $this->createBranch($companyB, 'BBX');

        $response = $this->actingAs($actorA)->get('/settings/branches');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('branches', 1)
            ->where('branches.0.code', 'AAX'));
    }

    public function test_a_user_with_permission_can_edit_a_branch_including_its_tracking_prefix_and_status(): void
    {
        $company = $this->createCompany('BRU');
        $branch = $this->createBranch($company, 'OLD');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['branches.view', 'branches.manage']);

        $this->actingAs($actor)
            ->patch("/settings/branches/{$branch->getKey()}", [
                'name' => 'Renamed Branch',
                'code' => 'NEWC',
                'tracking_prefix' => 'NEWP',
                'country_code' => 'AE',
                'city' => 'Abu Dhabi',
                'timezone' => 'Asia/Dubai',
                'status' => 'suspended',
                'is_head_office' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $branch->refresh();
        self::assertSame('Renamed Branch', $branch->name);
        self::assertSame('NEWP', $branch->tracking_prefix);
        self::assertSame('suspended', $branch->status);
        self::assertTrue($branch->is_head_office);
    }

    public function test_editing_a_branch_cannot_take_a_tracking_prefix_already_used_in_the_company(): void
    {
        $company = $this->createCompany('BRP');
        $taken = $this->createBranch($company, 'TKN');
        $branch = $this->createBranch($company, 'FRE');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['branches.view', 'branches.manage']);

        $this->actingAs($actor)
            ->patch("/settings/branches/{$branch->getKey()}", [
                'name' => $branch->name,
                'code' => $branch->code,
                'tracking_prefix' => $taken->tracking_prefix,
                'country_code' => 'AE',
                'city' => 'Dubai',
                'timezone' => 'Asia/Dubai',
            ])
            ->assertSessionHasErrors('tracking_prefix');

        self::assertSame('FRE', $branch->refresh()->tracking_prefix);
    }
}
