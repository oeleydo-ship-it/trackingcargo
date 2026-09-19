<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The numbering method the booking form starts with: a company default, which
 * a branch can override.
 */
final class DefaultTrackingModeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompany('DTM');
        $this->branch = $this->createBranch($this->company, 'DXB');
        $this->admin = $this->createUser($this->company);
        $this->grantPermissions($this->admin, ['companies.view', 'companies.manage', 'shipments.view']);
    }

    private function fresh(Company|Branch $model): Company|Branch
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $this->company->getKey());

        try {
            return $model->fresh();
        } finally {
            $context->forget();
        }
    }

    private function companySettings(array $overrides = []): array
    {
        return [
            'tracking_number_format' => '{company}-{branch}-{sequence}',
            'tracking_sequence_padding' => 8,
            'allow_manual_tracking_number' => false,
            ...$overrides,
        ];
    }

    public function test_a_new_company_starts_on_automatic_numbering(): void
    {
        self::assertSame('auto', $this->company->fresh()->default_tracking_mode);
        self::assertNull($this->fresh($this->branch)->default_tracking_mode);
    }

    public function test_the_company_default_can_be_set_to_receipt_reference(): void
    {
        $this->actingAs($this->admin)
            ->patch('/settings/tracking', $this->companySettings(['default_tracking_mode' => 'suffix']))
            ->assertSessionHasNoErrors();

        self::assertSame('suffix', $this->company->fresh()->default_tracking_mode);
    }

    public function test_entering_the_whole_number_can_only_be_the_default_when_manual_numbers_are_allowed(): void
    {
        $this->actingAs($this->admin)
            ->patch('/settings/tracking', $this->companySettings(['default_tracking_mode' => 'full']))
            ->assertSessionHasErrors('default_tracking_mode');

        self::assertSame('auto', $this->company->fresh()->default_tracking_mode);

        $this->actingAs($this->admin)
            ->patch('/settings/tracking', $this->companySettings(['allow_manual_tracking_number' => true, 'default_tracking_mode' => 'full']))
            ->assertSessionHasNoErrors();

        self::assertSame('full', $this->company->fresh()->default_tracking_mode);
    }

    public function test_an_unknown_method_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patch('/settings/tracking', $this->companySettings(['default_tracking_mode' => 'psychic']))
            ->assertSessionHasErrors('default_tracking_mode');
    }

    public function test_saving_the_pattern_without_a_method_leaves_the_default_alone(): void
    {
        $this->company->forceFill(['default_tracking_mode' => 'suffix'])->save();

        $this->actingAs($this->admin)->patch('/settings/tracking', $this->companySettings())->assertSessionHasNoErrors();

        self::assertSame('suffix', $this->company->fresh()->default_tracking_mode);
    }

    public function test_a_branch_can_start_on_its_own_method_and_go_back_to_following_the_company(): void
    {
        $this->actingAs($this->admin)
            ->patch("/settings/tracking/branches/{$this->branch->getKey()}", ['default_tracking_mode' => 'suffix'])
            ->assertSessionHasNoErrors();

        self::assertSame('suffix', $this->fresh($this->branch)->default_tracking_mode);

        $this->actingAs($this->admin)
            ->patch("/settings/tracking/branches/{$this->branch->getKey()}", ['default_tracking_mode' => ''])
            ->assertSessionHasNoErrors();

        self::assertNull($this->fresh($this->branch)->default_tracking_mode);
    }

    public function test_a_branch_cannot_default_to_the_whole_number_unless_the_company_allows_manual_numbers(): void
    {
        $this->actingAs($this->admin)
            ->patch("/settings/tracking/branches/{$this->branch->getKey()}", ['default_tracking_mode' => 'full'])
            ->assertSessionHasErrors('default_tracking_mode');

        self::assertNull($this->fresh($this->branch)->default_tracking_mode);
    }

    public function test_only_someone_who_manages_the_company_can_change_a_branchs_method(): void
    {
        $viewer = $this->createUser($this->company);
        $this->grantPermissions($viewer, ['companies.view']);

        $this->actingAs($viewer)
            ->patch("/settings/tracking/branches/{$this->branch->getKey()}", ['default_tracking_mode' => 'suffix'])
            ->assertForbidden();

        self::assertNull($this->fresh($this->branch)->default_tracking_mode);
    }

    public function test_another_companys_branch_cannot_be_changed(): void
    {
        $other = $this->createCompany('DTN');
        $otherBranch = $this->createBranch($other, 'MNL');

        $this->actingAs($this->admin)
            ->patch("/settings/tracking/branches/{$otherBranch->getKey()}", ['default_tracking_mode' => 'suffix'])
            ->assertNotFound();
    }

    public function test_the_booking_form_is_told_the_company_default_and_each_branch_override(): void
    {
        $auh = $this->createBranch($this->company, 'AUH');
        $this->company->forceFill(['default_tracking_mode' => 'suffix'])->save();
        $this->actingAs($this->admin)->patch("/settings/tracking/branches/{$auh->getKey()}", ['default_tracking_mode' => 'auto']);

        $this->actingAs($this->admin)->get('/shipments')->assertOk()->assertInertia(fn ($page) => $page
            ->where('trackingSettings.defaultMode', 'suffix')
            ->has('trackingSettings.branchModes', 1)
            ->where('trackingSettings.branchModes.0.branch_id', $auh->getKey())
            ->where('trackingSettings.branchModes.0.mode', 'auto'));
    }

    public function test_the_settings_page_lists_each_branchs_own_method(): void
    {
        $this->actingAs($this->admin)->patch("/settings/tracking/branches/{$this->branch->getKey()}", ['default_tracking_mode' => 'suffix']);

        $this->actingAs($this->admin)->get('/settings/tracking')->assertOk()->assertInertia(fn ($page) => $page
            ->where('company.default_tracking_mode', 'auto')
            ->where('branches.0.default_tracking_mode', 'suffix'));
    }
}
