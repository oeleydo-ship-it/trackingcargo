<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Company;
use App\Models\Shipment;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class TrackingNumberSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function basePayload(int $branchId, array $overrides = []): array
    {
        return [
            'branch_id' => $branchId,
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [
                ['weight_kg' => 5],
            ],
            ...$overrides,
        ];
    }

    private function latestShipment(Company $company): Shipment
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return Shipment::query()->latest('id')->firstOrFail();
        } finally {
            $context->forget();
        }
    }

    public function test_a_company_admin_can_change_the_tracking_number_pattern(): void
    {
        $company = $this->createCompany('TNA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        $this->actingAs($actor)
            ->patch('/settings/tracking', [
                'tracking_number_format' => '{branch}-{year}-{sequence}',
                'tracking_sequence_padding' => 5,
                'allow_manual_tracking_number' => true,
            ])
            ->assertRedirect();

        $company->refresh();
        self::assertSame('{branch}-{year}-{sequence}', $company->tracking_number_format);
        self::assertSame(5, $company->tracking_sequence_padding);
        self::assertTrue($company->allow_manual_tracking_number);
    }

    public function test_a_pattern_writing_the_company_code_as_a_token_is_rejected(): void
    {
        $company = $this->createCompany('SGFS');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        // The braces are the trap: {SGFS} is not a token, so it would be copied
        // into every tracking number literally and break the /track/{...} URL.
        $this->actingAs($actor)
            ->patch('/settings/tracking', [
                'tracking_number_format' => '{SGFS}-{branch}-{sequence}',
                'tracking_sequence_padding' => 8,
                'allow_manual_tracking_number' => false,
            ])
            ->assertSessionHasErrors('tracking_number_format');

        self::assertSame('{company}-{branch}-{sequence}', $company->refresh()->tracking_number_format);
    }

    public function test_a_pattern_with_a_slash_is_rejected_because_it_would_split_the_tracking_url(): void
    {
        $company = $this->createCompany('TNO');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        $this->actingAs($actor)
            ->patch('/settings/tracking', [
                'tracking_number_format' => '{branch}/{sequence}',
                'tracking_sequence_padding' => 8,
                'allow_manual_tracking_number' => false,
            ])
            ->assertSessionHasErrors('tracking_number_format');
    }

    public function test_a_shipment_page_stays_reachable_for_a_company_whose_pattern_was_saved_before_validation(): void
    {
        $company = $this->createCompany('TNP');
        // Written straight to the column, as an older release would have left it.
        $company->forceFill(['tracking_number_format' => '{TNP}-{branch}-{sequence}'])->save();
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)
            ->post('/shipments', $this->basePayload((int) $branch->getKey()))
            ->assertSessionHasErrors('tracking_number');

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_a_pattern_without_the_sequence_token_is_rejected(): void
    {
        $company = $this->createCompany('TNB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        $this->actingAs($actor)
            ->patch('/settings/tracking', [
                'tracking_number_format' => '{company}-{branch}',
                'tracking_sequence_padding' => 8,
                'allow_manual_tracking_number' => false,
            ])
            ->assertSessionHasErrors('tracking_number_format');

        self::assertSame('{company}-{branch}-{sequence}', $company->refresh()->tracking_number_format);
    }

    public function test_a_user_without_company_permission_cannot_change_the_pattern(): void
    {
        $company = $this->createCompany('TNC');
        $actor = $this->createUser($company);

        $this->actingAs($actor)
            ->patch('/settings/tracking', [
                'tracking_number_format' => '{sequence}',
                'tracking_sequence_padding' => 8,
                'allow_manual_tracking_number' => true,
            ])
            ->assertForbidden();
    }

    public function test_a_booked_shipment_uses_the_companys_configured_pattern(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00'));

        $company = $this->createCompany('TND');
        $company->forceFill(['tracking_number_format' => '{branch}-{year}{month}-{sequence}', 'tracking_sequence_padding' => 4])->save();
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)
            ->post('/shipments', $this->basePayload((int) $branch->getKey()))
            ->assertRedirect();

        self::assertSame('DXB-202609-0001', $this->latestShipment($company)->tracking_number);
    }

    public function test_a_pattern_carrying_a_month_restarts_the_sequence_each_month(): void
    {
        $company = $this->createCompany('TNE');
        $company->forceFill(['tracking_number_format' => '{branch}{yy}{month}{sequence}', 'tracking_sequence_padding' => 3])->save();
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00'));
        $this->actingAs($actor)->post('/shipments', $this->basePayload((int) $branch->getKey()))->assertRedirect();
        $this->actingAs($actor)->post('/shipments', $this->basePayload((int) $branch->getKey()))->assertRedirect();
        self::assertSame('DXB2609002', $this->latestShipment($company)->tracking_number);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
        $this->actingAs($actor)->post('/shipments', $this->basePayload((int) $branch->getKey()))->assertRedirect();
        self::assertSame('DXB2610001', $this->latestShipment($company)->tracking_number);
    }

    public function test_a_manual_tracking_number_is_rejected_while_the_company_has_not_opted_in(): void
    {
        $company = $this->createCompany('TNF');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)
            ->post('/shipments', $this->basePayload((int) $branch->getKey(), ['tracking_number' => 'CUSTOM-0001']))
            ->assertSessionHasErrors('tracking_number');

        $this->assertDatabaseMissing('shipments', ['tracking_number' => 'CUSTOM-0001']);
    }

    public function test_a_manual_tracking_number_is_used_verbatim_once_the_company_opts_in(): void
    {
        $company = $this->createCompany('TNG');
        $company->forceFill(['allow_manual_tracking_number' => true])->save();
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)
            ->post('/shipments', $this->basePayload((int) $branch->getKey(), ['tracking_number' => 'CUSTOM-0001']))
            ->assertRedirect();

        self::assertSame('CUSTOM-0001', $this->latestShipment($company)->tracking_number);
    }

    public function test_a_blank_manual_tracking_number_falls_back_to_the_generated_one(): void
    {
        $company = $this->createCompany('TNH');
        $company->forceFill(['allow_manual_tracking_number' => true])->save();
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)
            ->post('/shipments', $this->basePayload((int) $branch->getKey(), ['tracking_number' => '']))
            ->assertRedirect();

        self::assertSame('TNH-DXB-00000001', $this->latestShipment($company)->tracking_number);
    }

    public function test_a_tracking_number_already_in_use_is_rejected(): void
    {
        $company = $this->createCompany('TNI');
        $company->forceFill(['allow_manual_tracking_number' => true])->save();
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)->post('/shipments', $this->basePayload((int) $branch->getKey(), ['tracking_number' => 'DUPE-0001']))->assertRedirect();

        $this->actingAs($actor)
            ->post('/shipments', $this->basePayload((int) $branch->getKey(), ['tracking_number' => 'DUPE-0001']))
            ->assertSessionHasErrors('tracking_number');
    }

    public function test_a_draft_shipment_can_be_renumbered_but_only_while_manual_numbers_are_allowed(): void
    {
        $company = $this->createCompany('TNJ');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)->post('/shipments', $this->basePayload((int) $branch->getKey()))->assertRedirect();
        $shipment = $this->latestShipment($company);

        $payload = [
            'branch_id' => $branch->getKey(),
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'tracking_number' => 'RENAMED-0001',
        ];

        $this->actingAs($actor)
            ->patch("/shipments/{$shipment->getKey()}", $payload)
            ->assertSessionHasErrors('tracking_number');

        $company->forceFill(['allow_manual_tracking_number' => true])->save();

        $this->actingAs($actor)
            ->patch("/shipments/{$shipment->getKey()}", $payload)
            ->assertRedirect();

        self::assertSame('RENAMED-0001', $shipment->refresh()->tracking_number);
    }


    public function test_the_booking_page_hands_the_form_its_branches_and_the_companys_tracking_settings(): void
    {
        $company = $this->createCompany('TNK');
        $company->forceFill(['tracking_number_format' => '{branch}-{sequence}', 'tracking_sequence_padding' => 6, 'allow_manual_tracking_number' => true])->save();
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['shipments.view', 'branches.manage']);

        $this->actingAs($actor)
            ->get('/shipments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('branches', 1)
                ->where('branches.0.tracking_prefix', 'DXB')
                ->where('trackingSettings.companyCode', 'TNK')
                ->where('trackingSettings.format', '{branch}-{sequence}')
                ->where('trackingSettings.padding', 6)
                ->where('trackingSettings.allowManual', true)
                ->etc());

        self::assertNotNull($branch->getKey());
    }

    public function test_a_branch_scoped_clerk_is_only_offered_their_own_branch(): void
    {
        $company = $this->createCompany('TNL');
        $home = $this->createBranch($company, 'DXB');
        $this->createBranch($company, 'AUH');
        $actor = $this->createUser($company, $home);
        $this->grantPermissions($actor, ['shipments.view']);

        $this->actingAs($actor)
            ->get('/shipments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('branches', 1)
                ->where('branches.0.tracking_prefix', 'DXB')
                ->etc());
    }


    public function test_the_tracking_settings_tab_renders_the_current_pattern_with_a_sample_branch_prefix(): void
    {
        $company = $this->createCompany('TNM');
        $company->forceFill(['tracking_number_format' => '{branch}-{sequence}'])->save();
        $this->createBranch($company, 'AUH');
        $head = $this->createBranch($company, 'DXB');
        $head->forceFill(['is_head_office' => true])->save();
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view']);

        $this->actingAs($actor)
            ->get('/settings/tracking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/TrackingNumbers')
                ->where('company.tracking_number_format', '{branch}-{sequence}')
                ->where('sampleBranchPrefix', 'DXB')
                ->has('tokens')
                ->etc());
    }

    public function test_a_user_without_company_view_permission_cannot_open_the_tracking_settings_tab(): void
    {
        $company = $this->createCompany('TNN');
        $actor = $this->createUser($company);

        $this->actingAs($actor)
            ->get('/settings/tracking')
            ->assertForbidden();
    }


    public function test_a_company_admin_can_change_the_batch_number_pattern(): void
    {
        $company = $this->createCompany('TNQ');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        $this->actingAs($actor)
            ->get('/settings/batches')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Batches')->etc());

        $this->actingAs($actor)
            ->patch('/settings/batches', [
                'batch_number_format' => 'B{yy}{month}-{branch}-{sequence}',
                'batch_sequence_padding' => 4,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $company->refresh();
        self::assertSame('B{yy}{month}-{branch}-{sequence}', $company->batch_number_format);
        self::assertSame(4, $company->batch_sequence_padding);
    }

    public function test_the_batch_pattern_is_held_to_the_same_token_rules_as_tracking_numbers(): void
    {
        $company = $this->createCompany('TNR');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        $this->actingAs($actor)
            ->patch('/settings/batches', [
                'batch_number_format' => '{TNR}-{sequence}',
                'batch_sequence_padding' => 4,
            ])
            ->assertSessionHasErrors('batch_number_format');

        self::assertSame('BATCH-{branch}-{sequence}', $company->refresh()->batch_number_format);
    }

    public function test_a_user_without_company_permission_cannot_change_the_batch_pattern(): void
    {
        $company = $this->createCompany('TNS');
        $actor = $this->createUser($company);

        $this->actingAs($actor)
            ->patch('/settings/batches', ['batch_number_format' => '{sequence}', 'batch_sequence_padding' => 4])
            ->assertForbidden();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
