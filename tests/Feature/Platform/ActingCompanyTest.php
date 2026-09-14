<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * A platform admin has no company of their own (company_id is always null),
 * so every tenant-scoped screen is otherwise unreachable to them even though
 * Gate::before grants every ability unconditionally — see
 * ResolveTenant::resolvePlatformAdmin(). These tests exercise the "act as a
 * company" mechanism that makes Settings (and, by the same TenantContext
 * plumbing, every other tenant-scoped screen) actually usable by a
 * superadmin, not just theoretically authorized.
 */
final class ActingCompanyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_platform_admin_sees_no_company_selected_by_default(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->actingAs($admin)->get('/settings/company');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('company', null)
            ->where('isPlatformAdmin', true)
            ->has('companies'));
    }

    public function test_a_platform_admin_can_act_as_a_company_and_manage_its_settings(): void
    {
        $admin = $this->platformAdmin();
        $company = $this->createCompany('PAA');

        $this->actingAs($admin)->post("/platform/act-as/{$company->getKey()}")->assertRedirect('/settings/company');

        $show = $this->actingAs($admin)->get('/settings/company');
        $show->assertOk();
        $show->assertInertia(fn ($page) => $page->where('company.id', $company->getKey()));

        $this->actingAs($admin)
            ->patch('/settings/company', [
                'name' => 'Renamed By Admin',
                'timezone' => 'Asia/Dubai',
                'default_currency' => 'AED',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', ['id' => $company->getKey(), 'name' => 'Renamed By Admin']);
    }

    public function test_a_platform_admin_can_create_a_branch_for_the_company_they_are_acting_as(): void
    {
        $admin = $this->platformAdmin();
        $companyA = $this->createCompany('PAB');
        $companyB = $this->createCompany('PAC');
        // A branch code that already exists in a DIFFERENT company must not
        // block creation in the one actually being acted on — this is
        // exactly the bug that reading company_id from $user directly would
        // cause (it would scope the uniqueness check to company_id = null).
        $this->createBranch($companyB, 'DXB');

        $this->actingAs($admin)->post("/platform/act-as/{$companyA->getKey()}");

        $this->actingAs($admin)
            ->post('/settings/branches', [
                'name' => 'Dubai Branch',
                'code' => 'DXB',
                'tracking_prefix' => 'DXB',
                'country_code' => 'AE',
                'city' => 'Dubai',
                'timezone' => 'Asia/Dubai',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('branches', ['company_id' => $companyA->getKey(), 'code' => 'DXB']);
    }

    public function test_stopping_acting_as_a_company_returns_to_the_unscoped_state(): void
    {
        $admin = $this->platformAdmin();
        $company = $this->createCompany('PAD');

        $this->actingAs($admin)->post("/platform/act-as/{$company->getKey()}");
        $this->actingAs($admin)->delete('/platform/act-as')->assertRedirect('/settings/company');

        $response = $this->actingAs($admin)->get('/settings/company');
        $response->assertInertia(fn ($page) => $page->where('company', null));
    }

    public function test_acting_as_an_invalid_company_id_falls_back_to_the_unscoped_state(): void
    {
        $admin = $this->platformAdmin();

        // No act-as call at all — simulates a stale/tampered session value by
        // exercising the fallback path directly (a nonexistent company id
        // was never actually POSTable in the first place, since the route
        // model binding on ActingCompanyController::store() would 404 it —
        // this proves ResolveTenant's own existence re-check, not just the
        // controller's).
        $this->withSession(['platform_acting_company_id' => 999999])
            ->actingAs($admin)
            ->get('/settings/company')
            ->assertInertia(fn ($page) => $page->where('company', null));
    }

    public function test_an_ordinary_user_cannot_act_as_another_company(): void
    {
        $ownCompany = $this->createCompany('PAE');
        $otherCompany = $this->createCompany('PAF');
        $actor = $this->createUser($ownCompany);

        $this->actingAs($actor)->post("/platform/act-as/{$otherCompany->getKey()}")->assertForbidden();
    }

    public function test_acting_as_a_nonexistent_company_is_rejected(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post('/platform/act-as/999999')->assertNotFound();
    }

    private function platformAdmin(): User
    {
        $admin = new User;
        $admin->forceFill([
            'name' => 'Platform Admin',
            'email' => 'acting-company-admin-'.random_int(10000, 99999).'@example.test',
            'password' => bcrypt('secret-password'),
            'status' => UserStatus::Active,
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ])->save();

        return $admin;
    }
}
