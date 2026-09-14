<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class EmailVerificationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_signed_verification_link_verifies_the_users_email(): void
    {
        Event::fake();

        $company = $this->createCompany('EVT');
        $user = $this->createUser($company);
        $user->forceFill(['email_verified_at' => null])->save();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect();

        $user->refresh();
        self::assertNotNull($user->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_an_invalid_hash_is_rejected(): void
    {
        $company = $this->createCompany('EVI');
        $user = $this->createUser($company);
        $user->forceFill(['email_verified_at' => null])->save();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1('wrong-email@example.test'),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();

        $user->refresh();
        self::assertNull($user->email_verified_at);
    }
}
