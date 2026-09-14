<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class AccountFeaturesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function account(): User
    {
        return $this->createUser($this->createCompany('ACCT'));
    }

    public function test_totp_matches_rfc_vectors(): void
    {
        $totp = app(TwoFactorService::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        self::assertSame('287082', $totp->code($secret, 1));
        self::assertSame('081804', $totp->code($secret, intdiv(1111111109, 30)));
        self::assertSame('050471', $totp->code($secret, intdiv(1111111111, 30)));
    }

    public function test_expired_setup_cannot_enable_two_factor(): void
    {
        $user = $this->account();
        $this->actingAs($user)->post('/account/security/enable', ['password' => 'secret-password']);
        $secret = Crypt::decryptString(session('two_factor_setup.secret'));
        $this->travel(11)->minutes();
        $code = app(TwoFactorService::class)->code($secret, intdiv(now()->timestamp, 30));
        $this->post('/account/security/confirm', ['password' => 'secret-password', 'setup_code' => $code])->assertSessionHasErrors('setup_code');
        self::assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_recovery_codes_can_be_replaced_and_used_to_disable_two_factor(): void
    {
        $user = $this->account();
        $service = app(TwoFactorService::class);
        $user->forceFill(['two_factor_secret' => Crypt::encryptString($service->secret()), 'two_factor_confirmed_at' => now()])->save();
        $oldCodes = $service->recoveryCodes($user);
        $this->actingAs($user)->from('/account')->post('/account/security/recovery', ['password' => 'secret-password', 'code' => $oldCodes[0]])->assertSessionHasNoErrors();
        $newCodes = session('account_recovery_codes');
        self::assertFalse($service->verify($user, $oldCodes[1]));
        $this->post('/account/security/disable', ['password' => 'secret-password', 'code' => $newCodes[0]])->assertSessionHasNoErrors();
        self::assertNull($user->fresh()->two_factor_confirmed_at);
        self::assertNull($user->fresh()->two_factor_secret);
        self::assertNull($user->fresh()->two_factor_recovery_codes);
    }

    public function test_password_update_revokes_tokens_and_does_not_flash_passwords(): void
    {
        $user = $this->account();
        $user->createToken('old');
        $this->actingAs($user)->patch('/account', ['name' => 'Updated', 'password' => 'secret-password', 'new_password' => 'NewPassword123', 'new_password_confirmation' => 'NewPassword123'])->assertSessionHasNoErrors();
        self::assertSame('Updated', $user->fresh()->name);
        self::assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword123', $user->fresh()->password));
        self::assertSame(0, $user->tokens()->count());
        $this->patch('/account', ['name' => '', 'password' => 'NewPassword123', 'new_password' => 'DoNotFlash123', 'new_password_confirmation' => 'bad'])->assertSessionHasErrors();
        self::assertArrayNotHasKey('new_password', session()->getOldInput());
    }

    public function test_enrollment_requires_confirmation_revokes_tokens_and_codes_cannot_be_reused(): void
    {
        $user = $this->account();
        $user->createToken('old');
        $this->actingAs($user)->from('/account')->post('/account/security/enable', ['password' => 'secret-password'])->assertSessionHasNoErrors();
        $secret = Crypt::decryptString(session('two_factor_setup.secret'));
        $this->get('/account')->assertOk()->assertInertia(fn ($page) => $page->where('setupSecret', $secret)->where('setupQr', fn ($value) => str_starts_with($value, 'data:image/svg+xml;base64,')));
        self::assertNull($user->fresh()->two_factor_confirmed_at);
        $code = app(TwoFactorService::class)->code($secret, intdiv(now()->timestamp, 30));
        $this->post('/account/security/confirm', ['password' => 'secret-password', 'setup_code' => $code])->assertSessionHasNoErrors();
        $user = $user->fresh();
        self::assertNotNull($user->two_factor_confirmed_at);
        self::assertNotSame($secret, $user->two_factor_secret);
        self::assertSame($secret, Crypt::decryptString($user->two_factor_secret));
        self::assertSame(0, $user->tokens()->count());
        $recovery = session('account_recovery_codes');
        self::assertCount(8, $recovery);
        self::assertStringNotContainsString($recovery[0], $user->two_factor_recovery_codes);
        self::assertFalse(app(TwoFactorService::class)->verify($user, $code));
        self::assertTrue(app(TwoFactorService::class)->verify($user, $recovery[0]));
        self::assertFalse(app(TwoFactorService::class)->verify($user, $recovery[0]));
    }

    public function test_web_and_api_login_require_second_factor(): void
    {
        $user = $this->account();
        $totp = app(TwoFactorService::class);
        $secret = $totp->secret();
        $user->forceFill(['two_factor_secret' => Crypt::encryptString($secret), 'two_factor_confirmed_at' => now()])->save();
        $credentials = ['email' => $user->email, 'password' => 'secret-password'];
        $this->post('/login', $credentials)->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->postJson('/api/v1/login', [...$credentials, 'device_name' => 'test'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->post('/login', [...$credentials, 'code' => $totp->code($secret, intdiv(now()->timestamp, 30))])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_security_changes_require_password_and_enabled_second_factor(): void
    {
        $user = $this->account();
        $this->actingAs($user)->post('/account/security/enable', ['password' => 'wrong'])->assertSessionHasErrors('password');
        self::assertNull(session('two_factor_setup'));
        $user->forceFill(['two_factor_secret' => Crypt::encryptString(app(TwoFactorService::class)->secret()), 'two_factor_confirmed_at' => now()])->save();
        $this->post('/account/security/disable', ['password' => 'secret-password'])->assertSessionHasErrors('code');
        self::assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_tokens_are_shown_once_and_cannot_be_revoked_by_another_user(): void
    {
        $user = $this->account();
        $this->actingAs($user)->from('/account')->post('/account/tokens', ['password' => 'secret-password', 'name' => 'Scanner', 'days' => 7])->assertSessionHasNoErrors();
        $plain = session('account_new_token');
        self::assertNotEmpty($plain);
        $this->get('/account')->assertOk()->assertInertia(fn ($page) => $page->where('newToken', $plain)->has('tokens', 1));
        $this->get('/account')->assertInertia(fn ($page) => $page->where('newToken', null));
        $other = $this->createUser($user->company);
        $token = $user->tokens()->firstOrFail();
        $this->actingAs($other)->delete('/account/tokens/'.$token->id, ['password' => 'secret-password'])->assertNotFound();
        $this->actingAs($user)->delete('/account/tokens/'.$token->id, ['password' => 'secret-password'])->assertSessionHasNoErrors();
        self::assertSame(0, $user->tokens()->count());
    }

    public function test_notifications_are_private_and_support_read_and_unread(): void
    {
        $user = $this->account();
        $other = $this->createUser($user->company);
        $id = (string) Str::uuid();
        $user->notifications()->create(['id' => $id, 'type' => 'test', 'data' => ['message' => 'Private update']]);
        $this->actingAs($other)->get('/notifications')->assertInertia(fn ($page) => $page->has('notifications.data', 0));
        $this->patch('/notifications/'.$id, ['read' => true])->assertNotFound();
        $this->actingAs($user)->get('/notifications')->assertInertia(fn ($page) => $page->has('notifications.data', 1)->where('unreadCount', 1));
        $this->patch('/notifications/'.$id, ['read' => true])->assertSessionHasNoErrors();
        $this->get('/notifications?unread=1')->assertInertia(fn ($page) => $page->has('notifications.data', 0));
        $this->patch('/notifications/'.$id, ['read' => false]);
        $this->post('/notifications/read-all');
        self::assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_other_sessions_and_remember_tokens_can_be_revoked_without_touching_other_users(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->account();
        $other = $this->createUser($user->company);
        foreach ([['id' => 'old-own', 'user_id' => $user->id], ['id' => 'other-user', 'user_id' => $other->id]] as $session) {
            DB::table('sessions')->insert([...$session, 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        $this->actingAs($user)->post('/account/security/sessions', ['password' => 'secret-password'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-own']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user']);
        self::assertNotNull($user->fresh()->remember_token);
    }
}
