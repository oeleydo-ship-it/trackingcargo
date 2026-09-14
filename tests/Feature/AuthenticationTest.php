<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_branch_login_shows_an_inline_error_and_existing_session_returns_to_login(): void
    {
        $user = $this->user(UserStatus::Active);
        $branch = new Branch;
        app(TenantContext::class)->resolveCompany((int) $user->company_id);
        $branch->forceFill(['company_id' => $user->company_id, 'code' => 'DXB', 'tracking_prefix' => 'DXB', 'name' => 'Dubai', 'country_code' => 'AE', 'city' => 'Dubai', 'timezone' => 'Asia/Dubai', 'status' => 'active'])->save();
        app(TenantContext::class)->forget();
        $user->forceFill(['branch_id' => $branch->id])->save();
        $branch->delete();
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_active_company_user_can_authenticate(): void
    {
        $user = $this->user(UserStatus::Active);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->getKey(), 'action' => 'auth.login']);
    }

    public function test_suspended_user_cannot_authenticate(): void
    {
        $user = $this->user(UserStatus::Suspended);

        $response = $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'secret-password']);

        $response->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_platform_administrator_can_reach_the_dashboard_without_a_company(): void
    {
        $admin = new User;
        $admin->forceFill([
            'company_id' => null,
            'is_platform_admin' => true,
            'name' => 'Platform Admin',
            'email' => 'platform-admin@example.test',
            'password' => Hash::make('secret-password'),
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ]);
        $admin->save();

        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_a_company_user_without_a_company_is_rejected(): void
    {
        $user = new User;
        $user->forceFill([
            'company_id' => null,
            'is_platform_admin' => false,
            'name' => 'Orphan User',
            'email' => 'orphan@example.test',
            'password' => Hash::make('secret-password'),
            'status' => UserStatus::Active,
        ]);
        $user->save();

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    private function user(UserStatus $status): User
    {
        $company = Company::query()->create([
            'code' => 'AUTH',
            'name' => 'Authentication Company',
            'slug' => 'authentication-company',
            'country_code' => 'AE',
        ]);

        $user = new User;
        $user->forceFill([
            'company_id' => $company->getKey(),
            'name' => 'Operations User',
            'email' => strtolower($status->value).'@example.test',
            'password' => Hash::make('secret-password'),
            'status' => $status,
        ]);
        $user->save();

        return $user;
    }
}
