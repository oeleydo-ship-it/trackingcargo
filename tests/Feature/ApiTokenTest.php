<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class ApiTokenTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_api_login_returns_token_me_returns_owner_and_logout_revokes_it(): void
    {
        $user = $this->createUser($this->createCompany('API'));
        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secret-password', 'device_name' => 'Integration']);
        $response->assertCreated()->assertJsonPath('data.user.email', $user->email);
        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $user->email);
        $this->withToken($token)->deleteJson('/api/v1/logout')->assertNoContent();
        self::assertSame(0, $user->tokens()->count());
    }

    public function test_invalid_api_credentials_do_not_issue_a_token(): void
    {
        $user = $this->createUser($this->createCompany('BADAPI'));
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'Integration'])->assertUnprocessable();
        self::assertSame(0, $user->tokens()->count());
    }
}
