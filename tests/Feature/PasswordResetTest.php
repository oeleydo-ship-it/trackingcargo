<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_reset_link_can_be_requested_and_used_to_reset_the_password(): void
    {
        Notification::fake();

        $company = $this->createCompany('PRT');
        $user = $this->createUser($company);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect('/login');

        $this->post('/login', ['email' => $user->email, 'password' => 'a-brand-new-password'])
            ->assertRedirect('/dashboard');
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $company = $this->createCompany('PRI');
        $user = $this->createUser($company);

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('email');
    }
}
