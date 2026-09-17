<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class PublicTrackingSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @param array<string, string> $overrides */
    private function payload(array $overrides = []): array
    {
        $full = ['name' => 'full', 'address' => 'full', 'phone' => 'full', 'email' => 'full'];

        return ['parties' => ['sender' => [...$full, ...$overrides], 'receiver' => $full]];
    }

    public function test_the_tab_renders_the_levels_in_force_for_a_company_that_never_saved_them(): void
    {
        $company = $this->createCompany('PVA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view']);

        $this->actingAs($actor)
            ->get('/settings/public-tracking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/PublicTracking')
                ->where('parties.sender.name', 'full')
                ->where('parties.sender.phone', 'hidden')
                ->where('parties.receiver.phone', 'full')
                ->where('parties.receiver.email', 'hidden')
                ->has('fields')
                ->has('levels', 3)
                ->etc());
    }

    public function test_a_company_admin_can_change_which_party_fields_are_public(): void
    {
        $company = $this->createCompany('PVB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        $this->actingAs($actor)
            ->patch('/settings/public-tracking', $this->payload(['phone' => 'masked', 'email' => 'hidden']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $stored = $company->refresh()->public_tracking_parties;
        self::assertSame('masked', $stored['sender']['phone']);
        self::assertSame('hidden', $stored['sender']['email']);
        self::assertSame('full', $stored['receiver']['phone']);
    }

    public function test_an_unknown_visibility_level_is_rejected(): void
    {
        $company = $this->createCompany('PVC');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        $this->actingAs($actor)
            ->patch('/settings/public-tracking', $this->payload(['phone' => 'everything']))
            ->assertSessionHasErrors('parties.sender.phone');

        self::assertNull($company->refresh()->public_tracking_parties);
    }

    public function test_a_partial_post_cannot_leave_a_field_unset(): void
    {
        $company = $this->createCompany('PVD');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        $this->actingAs($actor)
            ->patch('/settings/public-tracking', ['parties' => ['sender' => ['name' => 'full']]])
            ->assertSessionHasErrors(['parties.sender.address', 'parties.receiver.name']);

        self::assertNull($company->refresh()->public_tracking_parties);
    }

    public function test_a_user_without_company_permission_cannot_open_or_change_the_settings(): void
    {
        $company = $this->createCompany('PVE');
        $actor = $this->createUser($company);

        $this->actingAs($actor)->get('/settings/public-tracking')->assertForbidden();
        $this->actingAs($actor)->patch('/settings/public-tracking', $this->payload())->assertForbidden();
    }

    public function test_one_companys_settings_never_affect_another(): void
    {
        $a = $this->createCompany('PVF');
        $b = $this->createCompany('PVG');
        $actorA = $this->createUser($a);
        $this->grantPermissions($actorA, ['companies.view', 'companies.manage']);

        $this->actingAs($actorA)
            ->patch('/settings/public-tracking', $this->payload(['name' => 'hidden']))
            ->assertRedirect();

        self::assertSame('hidden', $a->refresh()->public_tracking_parties['sender']['name']);
        self::assertNull($b->refresh()->public_tracking_parties);
    }
}
