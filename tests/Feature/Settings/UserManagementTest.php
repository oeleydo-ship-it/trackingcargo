<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_invite_a_new_user_and_the_invitation_can_be_accepted(): void
    {
        Notification::fake();

        $company = $this->createCompany('INV');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view', 'users.manage']);

        $this->actingAs($actor)
            ->post('/settings/users', [
                'name' => 'Invitee',
                'email' => 'invitee@example.test',
                'phone' => null,
                'branch_id' => null,
            ])
            ->assertRedirect();

        $invitee = User::query()->where('email', 'invitee@example.test')->firstOrFail();
        self::assertSame(UserStatus::Invited, $invitee->status);
        self::assertSame($company->getKey(), $invitee->company_id);

        $this->post('/logout');

        $signedUrl = URL::temporarySignedRoute('invitation.store', now()->addDay(), ['user' => $invitee->getKey()]);

        $this->post($signedUrl, [
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
        ])->assertRedirect('/login');

        $invitee->refresh();
        self::assertSame(UserStatus::Active, $invitee->status);
        self::assertNotNull($invitee->email_verified_at);

        $this->post('/login', ['email' => $invitee->email, 'password' => 'a-new-secure-password'])
            ->assertRedirect('/dashboard');
    }

    public function test_a_user_without_permission_cannot_invite_a_user(): void
    {
        $company = $this->createCompany('INN');
        $actor = $this->createUser($company);

        $this->actingAs($actor)
            ->post('/settings/users', ['name' => 'Nope', 'email' => 'nope@example.test'])
            ->assertForbidden();
    }

    public function test_a_company_cannot_view_or_modify_another_companys_user(): void
    {
        $companyA = $this->createCompany('UMA');
        $companyB = $this->createCompany('UMB');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['users.view', 'users.manage']);
        $targetB = $this->createUser($companyB);

        $this->actingAs($actorA)
            ->patch("/settings/users/{$targetB->getKey()}", [
                'name' => 'Hijacked',
                'email' => $targetB->email,
            ])
            ->assertForbidden();

        $this->actingAs($actorA)
            ->post("/settings/users/{$targetB->getKey()}/suspend")
            ->assertForbidden();

        $targetB->refresh();
        self::assertSame(UserStatus::Active, $targetB->status);
    }

    public function test_user_listing_never_includes_another_companys_users(): void
    {
        $companyA = $this->createCompany('ULA');
        $companyB = $this->createCompany('ULB');
        $actorA = $this->createUser($companyA);
        $this->grantPermissions($actorA, ['users.view']);
        $this->createUser($companyB);

        $response = $this->actingAs($actorA)->get('/settings/users');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('users', 1));
    }

    public function test_suspending_a_user_blocks_authentication(): void
    {
        $company = $this->createCompany('SUS');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view', 'users.manage']);
        $target = $this->createUser($company);

        $this->actingAs($actor)
            ->post("/settings/users/{$target->getKey()}/suspend")
            ->assertRedirect();

        $target->refresh();
        self::assertSame(UserStatus::Suspended, $target->status);

        $this->post('/logout');

        $this->from('/login')->post('/login', ['email' => $target->email, 'password' => 'secret-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_user_cannot_suspend_themselves(): void
    {
        $company = $this->createCompany('SLF');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view', 'users.manage']);

        $this->actingAs($actor)
            ->post("/settings/users/{$actor->getKey()}/suspend")
            ->assertForbidden();
    }
}
