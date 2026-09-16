<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\TrackingNumberFormat;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Tracking-number formats per branch and mode, on top of the company default
 * pattern — e.g. SGFS-CS… for sea and SGFS-DCA… for air out of Dubai.
 */
final class MultiTrackingFormatTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Company $company;

    private Branch $head;

    private Branch $dubai;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompany('SGFS');
        $this->head = $this->createBranch($this->company, 'HQ');
        $this->dubai = $this->createBranch($this->company, 'DXB');
        $this->admin = $this->createUser($this->company);
        $this->grantPermissions($this->admin, ['companies.view', 'companies.manage', 'shipments.view', 'shipments.manage']);

        $this->withTenant(fn () => $this->company->forceFill([
            'tracking_number_format' => 'SGFS-{sequence}',
            'tracking_sequence_padding' => 6,
        ])->save());
    }

    public function test_sea_and_air_formats_each_keep_their_own_running_number(): void
    {
        $this->addFormat(mode: 'sea', format: 'SGFS-CS{sequence}');
        $this->addFormat(mode: 'air', format: 'SGFS-CA{sequence}');

        self::assertSame('SGFS-CS000001', $this->book($this->head, 'sea'));
        self::assertSame('SGFS-CA000001', $this->book($this->head, 'air'));
        self::assertSame('SGFS-CS000002', $this->book($this->head, 'sea'));
        self::assertSame('SGFS-CA000002', $this->book($this->head, 'air'));
    }

    public function test_a_branch_and_mode_format_beats_a_mode_only_one(): void
    {
        $this->addFormat(mode: 'sea', format: 'SGFS-CS{sequence}');
        $this->addFormat(mode: 'air', format: 'SGFS-CA{sequence}');
        $this->addFormat(branch: $this->dubai, mode: 'sea', format: 'SGFS-DCS{sequence}');
        $this->addFormat(branch: $this->dubai, mode: 'air', format: 'SGFS-DCA{sequence}');

        self::assertSame('SGFS-DCS000001', $this->book($this->dubai, 'sea'));
        self::assertSame('SGFS-DCA000001', $this->book($this->dubai, 'air'));
        self::assertSame('SGFS-CS000001', $this->book($this->head, 'sea'));
        self::assertSame('SGFS-CA000001', $this->book($this->head, 'air'));
    }

    public function test_a_branch_format_covers_every_mode_it_does_not_name(): void
    {
        $this->addFormat(branch: $this->dubai, format: 'SGFS-D{sequence}');
        $this->addFormat(branch: $this->dubai, mode: 'air', format: 'SGFS-DCA{sequence}');

        self::assertSame('SGFS-DCA000001', $this->book($this->dubai, 'air'));
        self::assertSame('SGFS-D000001', $this->book($this->dubai, 'sea'));
        self::assertSame('SGFS-D000002', $this->book($this->dubai, 'road'));
    }

    public function test_anything_not_covered_falls_back_to_the_company_pattern(): void
    {
        // Booked before any override exists.
        self::assertSame('SGFS-000001', $this->book($this->head, 'road'));

        $this->addFormat(mode: 'sea', format: 'SGFS-CS{sequence}');

        self::assertSame('SGFS-CS000001', $this->book($this->head, 'sea'));
        // The default keeps counting from where it was, rather than restarting
        // and colliding with numbers already issued.
        self::assertSame('SGFS-000002', $this->book($this->head, 'road'));
    }

    public function test_a_receipt_suffix_uses_the_matching_format(): void
    {
        $this->addFormat(mode: 'sea', format: 'SGFS-CS{sequence}');

        $this->actingAs($this->admin)
            ->post('/shipments', [...$this->booking($this->head, 'sea'), 'tracking_suffix' => 'R-9001'])
            ->assertSessionHasNoErrors();

        self::assertSame('SGFS-CSR-9001', $this->latestNumber());
    }

    public function test_the_booking_page_previews_the_matching_format(): void
    {
        $this->addFormat(mode: 'sea', format: 'SGFS-CS{sequence}');

        $this->actingAs($this->admin)
            ->get('/shipments')
            ->assertInertia(fn ($page) => $page
                ->where('trackingSettings.rules.0.format', 'SGFS-CS{sequence}')
                ->where('trackingSettings.rules.0.mode', 'sea'));
    }

    public function test_formats_can_be_added_edited_and_removed_from_settings(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/tracking/formats', ['branch_id' => null, 'mode' => 'sea', 'format' => 'SGFS-CS{sequence}', 'sequence_padding' => 6])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rule = $this->withTenant(fn () => TrackingNumberFormat::query()->sole());
        self::assertSame('sea', $rule->mode->value);

        $this->actingAs($this->admin)
            ->patch("/settings/tracking/formats/{$rule->getKey()}", ['branch_id' => $this->dubai->getKey(), 'mode' => 'sea', 'format' => 'SGFS-DCS{sequence}', 'sequence_padding' => 4])
            ->assertSessionHasNoErrors();

        self::assertSame('SGFS-DCS0001', $this->book($this->dubai, 'sea'));

        $this->actingAs($this->admin)->delete("/settings/tracking/formats/{$rule->getKey()}")->assertSessionHasNoErrors();

        self::assertSame(0, $this->withTenant(fn () => TrackingNumberFormat::query()->count()));
        self::assertSame('SGFS-000001', $this->book($this->dubai, 'sea'));
    }

    public function test_two_formats_cannot_cover_the_same_branch_and_mode(): void
    {
        $this->addFormat(mode: 'sea', format: 'SGFS-CS{sequence}');

        $this->actingAs($this->admin)
            ->post('/settings/tracking/formats', ['branch_id' => null, 'mode' => 'sea', 'format' => 'SGFS-SEA{sequence}', 'sequence_padding' => 6])
            ->assertSessionHasErrors('combination');

        self::assertSame(1, $this->withTenant(fn () => TrackingNumberFormat::query()->count()));
    }

    public function test_an_unusable_pattern_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/tracking/formats', ['branch_id' => null, 'mode' => 'sea', 'format' => '{SGFS}-CS{sequence}', 'sequence_padding' => 6])
            ->assertSessionHasErrors('format');

        $this->actingAs($this->admin)
            ->post('/settings/tracking/formats', ['branch_id' => null, 'mode' => 'sea', 'format' => 'SGFS-CS', 'sequence_padding' => 6])
            ->assertSessionHasErrors('format');

        self::assertSame(0, $this->withTenant(fn () => TrackingNumberFormat::query()->count()));
    }

    public function test_a_branch_from_another_company_is_rejected(): void
    {
        $foreign = $this->createBranch($this->createCompany('OTH'), 'MNL');

        $this->actingAs($this->admin)
            ->post('/settings/tracking/formats', ['branch_id' => $foreign->getKey(), 'mode' => 'sea', 'format' => 'SGFS-CS{sequence}', 'sequence_padding' => 6])
            ->assertSessionHasErrors('branch_id');
    }

    public function test_a_user_without_company_manage_cannot_change_formats(): void
    {
        $viewer = $this->createUser($this->company);
        $this->grantPermissions($viewer, ['companies.view', 'shipments.view']);

        $this->actingAs($viewer)
            ->post('/settings/tracking/formats', ['branch_id' => null, 'mode' => 'sea', 'format' => 'SGFS-CS{sequence}', 'sequence_padding' => 6])
            ->assertForbidden();

        self::assertSame(0, $this->withTenant(fn () => TrackingNumberFormat::query()->count()));
    }

    private function addFormat(?Branch $branch = null, ?string $mode = null, string $format = 'SGFS-{sequence}', int $padding = 6): TrackingNumberFormat
    {
        $this->actingAs($this->admin)
            ->post('/settings/tracking/formats', [
                'branch_id' => $branch?->getKey(),
                'mode' => $mode,
                'format' => $format,
                'sequence_padding' => $padding,
            ])
            ->assertSessionHasNoErrors();

        return $this->withTenant(fn () => TrackingNumberFormat::query()->latest('id')->firstOrFail());
    }

    private function book(Branch $branch, string $mode): string
    {
        $this->actingAs($this->admin)
            ->post('/shipments', $this->booking($branch, $mode))
            ->assertSessionHasNoErrors();

        return $this->latestNumber();
    }

    private function latestNumber(): string
    {
        return $this->withTenant(fn () => Shipment::query()->latest('id')->firstOrFail()->tracking_number);
    }

    private function booking(Branch $branch, string $mode): array
    {
        return [
            'branch_id' => $branch->getKey(),
            'mode' => $mode,
            'destination_country_code' => 'PH',
            'destination_city' => 'Manila',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [['weight_kg' => 5]],
        ];
    }

    private function withTenant(Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $this->company->getKey());

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
