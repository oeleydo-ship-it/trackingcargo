<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Settings → Users: getting someone back into their account, either with a
 * password the administrator sets and hands over, or with the usual emailed
 * reset link.
 */
final class UserPasswordResetTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_an_administrator_can_set_a_password_the_user_signs_in_with(): void
    {
        Notification::fake();

        [$actor, $target] = $this->company('UPR');
        $oldRememberToken = $target->remember_token;

        $this->actingAs($actor)
            ->post("/settings/users/{$target->getKey()}/password", [
                'method' => 'password',
                'password' => 'counter-staff-2026',
                'password_confirmation' => 'counter-staff-2026',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $target->refresh();
        self::assertTrue(Hash::check('counter-staff-2026', $target->password));
        Notification::assertNothingSent();

        // Every device they were signed in on is signed out with the reset.
        self::assertNotSame($oldRememberToken, $target->remember_token);

        $this->post('/logout');
        $this->post('/login', ['email' => $target->email, 'password' => 'counter-staff-2026'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($target);
    }

    public function test_the_password_never_reaches_the_audit_log(): void
    {
        [$actor, $target] = $this->company('UPS');

        $this->actingAs($actor)->post("/settings/users/{$target->getKey()}/password", [
            'method' => 'password',
            'password' => 'counter-staff-2026',
            'password_confirmation' => 'counter-staff-2026',
        ]);

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'user.password-set')->sole();

        self::assertSame((int) $target->getKey(), (int) $entry->subject_id);
        self::assertStringNotContainsString('counter-staff-2026', json_encode([$entry->old_values, $entry->new_values], JSON_THROW_ON_ERROR));
    }

    public function test_a_user_who_never_accepted_their_invitation_becomes_active(): void
    {
        [$actor, , $company] = $this->company('UPT');
        $invited = $this->createUser($company, status: UserStatus::Invited);
        $invited->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($actor)
            ->post("/settings/users/{$invited->getKey()}/password", [
                'method' => 'password',
                'password' => 'counter-staff-2026',
                'password_confirmation' => 'counter-staff-2026',
            ])
            ->assertSessionHasNoErrors();

        $invited->refresh();
        self::assertSame(UserStatus::Active, $invited->status);
        self::assertNotNull($invited->email_verified_at);
    }

    public function test_a_suspended_account_stays_suspended(): void
    {
        [$actor, , $company] = $this->company('UPU');
        $suspended = $this->createUser($company, status: UserStatus::Suspended);

        $this->actingAs($actor)
            ->post("/settings/users/{$suspended->getKey()}/password", [
                'method' => 'password',
                'password' => 'counter-staff-2026',
                'password_confirmation' => 'counter-staff-2026',
            ])
            ->assertSessionHasNoErrors();

        self::assertSame(UserStatus::Suspended, $suspended->refresh()->status);
    }

    public function test_a_reset_link_can_be_emailed_instead(): void
    {
        Notification::fake();

        [$actor, $target] = $this->company('UPV');
        $oldPassword = $target->password;

        $this->actingAs($actor)
            ->post("/settings/users/{$target->getKey()}/password", ['method' => 'email'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($target, ResetPassword::class);

        // Nothing about the account changes until they use the link.
        self::assertSame($oldPassword, $target->refresh()->password);
    }

    public function test_a_reset_link_is_refused_for_an_account_that_cannot_sign_in(): void
    {
        Notification::fake();

        [$actor, , $company] = $this->company('UPW');
        $suspended = $this->createUser($company, status: UserStatus::Suspended);

        $this->actingAs($actor)
            ->post("/settings/users/{$suspended->getKey()}/password", ['method' => 'email'])
            ->assertSessionHasErrors('method');

        Notification::assertNothingSent();
    }

    public function test_a_weak_or_mistyped_password_is_rejected(): void
    {
        [$actor, $target] = $this->company('UPX');
        $oldPassword = $target->password;

        $this->actingAs($actor)
            ->post("/settings/users/{$target->getKey()}/password", ['method' => 'password', 'password' => 'short1', 'password_confirmation' => 'short1'])
            ->assertSessionHasErrors('password');

        $this->actingAs($actor)
            ->post("/settings/users/{$target->getKey()}/password", ['method' => 'password', 'password' => 'counter-staff-2026', 'password_confirmation' => 'counter-staff-2027'])
            ->assertSessionHasErrors('password');

        self::assertSame($oldPassword, $target->refresh()->password);
    }

    public function test_you_cannot_reset_your_own_password_here(): void
    {
        [$actor] = $this->company('UPY');

        // My account asks for the current password; this screen would just
        // sign the administrator out of the session they are working in.
        $this->actingAs($actor)
            ->post("/settings/users/{$actor->getKey()}/password", [
                'method' => 'password',
                'password' => 'counter-staff-2026',
                'password_confirmation' => 'counter-staff-2026',
            ])
            ->assertForbidden();

        self::assertFalse(Hash::check('counter-staff-2026', $actor->refresh()->password));
    }

    public function test_a_user_of_another_company_cannot_be_reset(): void
    {
        [$actor] = $this->company('UPZ');
        [, $foreigner] = $this->company('OTH');
        $oldPassword = $foreigner->password;

        $this->actingAs($actor)
            ->post("/settings/users/{$foreigner->getKey()}/password", [
                'method' => 'password',
                'password' => 'counter-staff-2026',
                'password_confirmation' => 'counter-staff-2026',
            ])
            ->assertForbidden();

        self::assertSame($oldPassword, $foreigner->refresh()->password);
    }

    public function test_a_user_without_users_manage_cannot_reset_passwords(): void
    {
        [, $target, $company] = $this->company('UQA');
        $viewer = $this->createUser($company);
        $this->grantPermissions($viewer, ['users.view']);

        $this->actingAs($viewer)
            ->post("/settings/users/{$target->getKey()}/password", [
                'method' => 'password',
                'password' => 'counter-staff-2026',
                'password_confirmation' => 'counter-staff-2026',
            ])
            ->assertForbidden();
    }

    /** @return array{0: User, 1: User, 2: \App\Models\Company} */
    private function company(string $code): array
    {
        $company = $this->createCompany($code);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['users.view', 'users.manage']);

        return [$actor, $this->createUser($company), $company];
    }
}
