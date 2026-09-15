<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Settings → Users: adding a user with a password set by the administrator,
 * alongside the existing email invitation.
 */
final class UserPasswordCreationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_created_with_a_password_can_sign_in_straight_away(): void
    {
        Notification::fake();

        $company = $this->createCompany('UPA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view', 'users.manage']);

        $this->actingAs($actor)
            ->post('/settings/users', $this->payload(['branch_id' => $branch->getKey()]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'clerk@example.test')->firstOrFail();

        self::assertSame(UserStatus::Active, $user->status);
        self::assertSame($company->getKey(), $user->company_id);
        self::assertSame($branch->getKey(), $user->branch_id);
        self::assertNotNull($user->email_verified_at);
        self::assertTrue(Hash::check('clerk-password-2026', $user->password));
        Notification::assertNothingSent();

        $this->post('/logout');

        $this->post('/login', ['email' => 'clerk@example.test', 'password' => 'clerk-password-2026'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_invitation_is_still_the_default(): void
    {
        Notification::fake();

        $company = $this->createCompany('UPB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view', 'users.manage']);

        // No method sent, as the old form did: still an invitation, and a
        // stray password is ignored rather than activating the account.
        $this->actingAs($actor)
            ->post('/settings/users', ['name' => 'Invitee', 'email' => 'invitee@example.test', 'password' => 'ignored-password-1', 'password_confirmation' => 'ignored-password-1'])
            ->assertSessionHasNoErrors();

        $invitee = User::query()->where('email', 'invitee@example.test')->firstOrFail();

        self::assertSame(UserStatus::Invited, $invitee->status);
        self::assertFalse(Hash::check('ignored-password-1', $invitee->password));
        Notification::assertSentTo($invitee, UserInvited::class);
    }

    public function test_a_password_is_required_and_must_meet_the_policy(): void
    {
        $company = $this->createCompany('UPC');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view', 'users.manage']);

        $this->actingAs($actor)
            ->post('/settings/users', $this->payload(['password' => '', 'password_confirmation' => '']))
            ->assertSessionHasErrors('password');

        $this->actingAs($actor)
            ->post('/settings/users', $this->payload(['password' => 'short1', 'password_confirmation' => 'short1']))
            ->assertSessionHasErrors('password');

        $this->actingAs($actor)
            ->post('/settings/users', $this->payload(['password_confirmation' => 'something-else-99']))
            ->assertSessionHasErrors('password');

        self::assertFalse(User::query()->where('email', 'clerk@example.test')->exists());
    }

    public function test_the_password_never_reaches_the_audit_log(): void
    {
        $company = $this->createCompany('UPD');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view', 'users.manage']);

        $this->actingAs($actor)->post('/settings/users', $this->payload())->assertSessionHasNoErrors();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            $entry = AuditLog::query()->where('action', 'user.created')->firstOrFail();
        } finally {
            $context->forget();
        }

        self::assertStringNotContainsString('clerk-password-2026', json_encode($entry->new_values));
        self::assertSame('clerk@example.test', $entry->new_values['email']);
    }

    public function test_a_user_without_manage_permission_cannot_create_one(): void
    {
        $company = $this->createCompany('UPE');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view']);

        $this->actingAs($actor)->post('/settings/users', $this->payload())->assertForbidden();

        self::assertFalse(User::query()->where('email', 'clerk@example.test')->exists());
    }

    public function test_a_branch_from_another_company_is_rejected(): void
    {
        $company = $this->createCompany('UPF');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view', 'users.manage']);
        $foreignBranch = $this->createBranch($this->createCompany('UPG'), 'MNL');

        $this->actingAs($actor)
            ->post('/settings/users', $this->payload(['branch_id' => $foreignBranch->getKey()]))
            ->assertSessionHasErrors('branch_id');

        self::assertFalse(User::query()->where('email', 'clerk@example.test')->exists());
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'New Clerk',
            'email' => 'clerk@example.test',
            'phone' => null,
            'branch_id' => null,
            'method' => 'password',
            'password' => 'clerk-password-2026',
            'password_confirmation' => 'clerk-password-2026',
            ...$overrides,
        ];
    }
}
