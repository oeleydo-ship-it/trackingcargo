<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class LoginThrottleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        $company = $this->createCompany('THR');
        $user = $this->createUser($company);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);

        $response->assertSessionHasErrors('email');
        self::assertGuest();
    }
}
