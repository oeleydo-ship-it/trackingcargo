<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The optional "mode of payment" recorded on a shipment.
 */
final class ShipmentPaymentModeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompany('SPM');
        $this->branch = $this->createBranch($this->company, 'DXB');
        $this->actor = $this->createUser($this->company);
        $this->grantPermissions($this->actor, ['shipments.view', 'shipments.manage']);
    }

    private function booking(array $overrides = []): array
    {
        return [
            'branch_id' => $this->branch->getKey(),
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                ['role' => 'consignee', 'name' => 'Consignee One', 'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH']],
            ],
            'packages' => [['weight_kg' => 5]],
            ...$overrides,
        ];
    }

    private function latestShipment(): Shipment
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $this->company->getKey());

        try {
            return Shipment::query()->latest('id')->firstOrFail();
        } finally {
            $context->forget();
        }
    }

    public function test_a_shipment_can_be_booked_with_a_mode_of_payment(): void
    {
        $this->actingAs($this->actor)->post('/shipments', $this->booking(['payment_mode' => 'bank_transfer']))->assertSessionHasNoErrors();

        self::assertSame('bank_transfer', $this->latestShipment()->payment_mode);
    }

    public function test_the_mode_of_payment_is_optional(): void
    {
        $this->actingAs($this->actor)->post('/shipments', $this->booking())->assertSessionHasNoErrors();
        self::assertNull($this->latestShipment()->payment_mode);

        $this->actingAs($this->actor)->post('/shipments', $this->booking(['payment_mode' => '']))->assertSessionHasNoErrors();
        self::assertNull($this->latestShipment()->payment_mode);
    }

    public function test_an_unknown_mode_of_payment_is_rejected(): void
    {
        $this->actingAs($this->actor)->post('/shipments', $this->booking(['payment_mode' => 'bitcoin']))->assertSessionHasErrors('payment_mode');
    }

    public function test_the_mode_of_payment_can_be_changed_and_cleared_on_the_shipment(): void
    {
        $this->actingAs($this->actor)->post('/shipments', $this->booking(['payment_mode' => 'cash']));
        $shipment = $this->latestShipment();

        $edit = ['branch_id' => $this->branch->getKey(), 'mode' => 'air', 'destination_country_code' => 'PH'];

        $this->actingAs($this->actor)->patch("/shipments/{$shipment->getKey()}", [...$edit, 'payment_mode' => 'cod'])->assertSessionHasNoErrors();
        self::assertSame('cod', $this->latestShipment()->payment_mode);

        $this->actingAs($this->actor)->patch("/shipments/{$shipment->getKey()}", [...$edit, 'payment_mode' => ''])->assertSessionHasNoErrors();
        self::assertNull($this->latestShipment()->payment_mode);
    }

    public function test_the_shipment_page_carries_the_mode_of_payment(): void
    {
        $this->actingAs($this->actor)->post('/shipments', $this->booking(['payment_mode' => 'card']));
        $shipment = $this->latestShipment();

        $this->actingAs($this->actor)->get("/shipments/{$shipment->getKey()}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('shipment.payment_mode', 'card'));
    }

    public function test_the_mode_of_payment_never_reaches_the_public_tracking_page(): void
    {
        $this->actingAs($this->actor)->post('/shipments', $this->booking(['payment_mode' => 'cash']));
        $shipment = $this->latestShipment();

        $this->get("/track/{$shipment->tracking_number}")->assertOk()->assertDontSee('payment_mode', false);
    }

    public function test_company_can_add_rename_and_disable_a_payment_mode(): void
    {
        $this->grantPermissions($this->actor, ['companies.view', 'companies.manage']);

        $this->actingAs($this->actor)->post('/settings/payment-modes', ['label' => 'Mobile wallet'])->assertSessionHasNoErrors();
        $this->actingAs($this->actor)->post('/shipments', $this->booking(['payment_mode' => 'mobile_wallet']))->assertSessionHasNoErrors();
        self::assertSame('mobile_wallet', $this->latestShipment()->payment_mode);

        $this->actingAs($this->actor)->patch('/settings/payment-modes/mobile_wallet', ['label' => 'Digital wallet', 'active' => false])->assertSessionHasNoErrors();
        $this->actingAs($this->actor)->post('/shipments', $this->booking(['payment_mode' => 'mobile_wallet']))->assertSessionHasErrors('payment_mode');

        $shipment = $this->latestShipment();
        $this->actingAs($this->actor)->get("/shipments/{$shipment->getKey()}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('shipment.payment_mode', 'mobile_wallet')
                ->where('paymentModes.4.label', 'Digital wallet'));
    }

    public function test_payment_modes_are_isolated_between_companies(): void
    {
        $this->grantPermissions($this->actor, ['companies.manage']);
        $this->actingAs($this->actor)->post('/settings/payment-modes', ['label' => 'Mobile wallet'])->assertSessionHasNoErrors();

        $otherCompany = $this->createCompany('SP2');
        $otherBranch = $this->createBranch($otherCompany, 'ABC');
        $otherUser = $this->createUser($otherCompany);
        $this->grantPermissions($otherUser, ['shipments.view', 'shipments.manage']);

        $this->actingAs($otherUser)->post('/shipments', $this->booking(['branch_id' => $otherBranch->getKey(), 'payment_mode' => 'mobile_wallet']))
            ->assertSessionHasErrors('payment_mode');
    }
}
