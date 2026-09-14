<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * routes/channels.php's `company.{companyId}` and `user.{userId}` private
 * channels have backed every realtime feature since Phase 5 (scan board),
 * but were never directly proven to reject cross-tenant subscriptions —
 * Phase 9's acceptance criterion ("channels prevent cross-company
 * subscriptions") makes that explicit here, via the same
 * POST /broadcasting/auth endpoint a real Echo/Pusher client hits.
 *
 * phpunit.xml sets BROADCAST_CONNECTION=null for the rest of the suite
 * (NullBroadcaster::auth() is a no-op that skips authorization entirely —
 * fine for tests that only care that *something* got broadcast), so this
 * test swaps in a real Pusher-protocol driver (Reverb speaks the same
 * protocol) purely so `Broadcast::auth()` actually runs the channel
 * closures in routes/channels.php instead of short-circuiting.
 *
 * routes/channels.php already ran once during app boot and registered its
 * channels against whatever the *null* driver instance was at that point —
 * switching the `broadcasting.default` config afterward does not move those
 * registrations to a freshly-resolved pusher driver instance, so the file
 * is re-required here to register the same channel closures against it.
 */
final class BroadcastChannelAuthorizationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver' => 'pusher',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => ['cluster' => 'mt1', 'useTLS' => true],
            ],
        ]);

        require base_path('routes/channels.php');
    }

    public function test_a_user_can_authorize_their_own_companys_broadcast_channel(): void
    {
        $company = $this->createCompany('BCA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)
            ->post('/broadcasting/auth', [
                'channel_name' => "private-company.{$company->getKey()}",
                'socket_id' => '1234.5678',
            ])
            ->assertOk();
    }

    public function test_a_user_cannot_authorize_another_companys_broadcast_channel(): void
    {
        $companyA = $this->createCompany('BCB');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actor = $this->createUser($companyA, $branchA);

        $companyB = $this->createCompany('BCC');

        $this->actingAs($actor)
            ->post('/broadcasting/auth', [
                'channel_name' => "private-company.{$companyB->getKey()}",
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden();
    }

    public function test_a_user_can_authorize_their_own_private_user_channel_but_not_another_users(): void
    {
        $company = $this->createCompany('BCD');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $otherUser = $this->createUser($company, $branch);

        $this->actingAs($actor)
            ->post('/broadcasting/auth', [
                'channel_name' => "private-user.{$actor->getKey()}",
                'socket_id' => '1234.5678',
            ])
            ->assertOk();

        $this->actingAs($actor)
            ->post('/broadcasting/auth', [
                'channel_name' => "private-user.{$otherUser->getKey()}",
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden();
    }

    public function test_an_unauthenticated_visitor_cannot_authorize_any_private_channel(): void
    {
        $company = $this->createCompany('BCE');

        $this->post('/broadcasting/auth', [
            'channel_name' => "private-company.{$company->getKey()}",
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }
}
