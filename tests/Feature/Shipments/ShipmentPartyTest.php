<?php

declare(strict_types=1);

namespace Tests\Feature\Shipments;

use App\Enums\AddressType;
use App\Enums\CustomerType;
use App\Enums\ShipmentPartyRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Shipment;
use App\Models\ShipmentParty;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * The cargo-side parties on a shipment: the consignor who sends it and the
 * consignee who receives it. The carrier moving the box is not a party — it
 * stays on the shipment as carrier_code.
 */
final class ShipmentPartyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function basePayload(int $branchId): array
    {
        return [
            'branch_id' => $branchId,
            'mode' => 'air',
            'destination_country_code' => 'PH',
            'destination_city' => 'Manila',
            'parties' => [
                ['role' => 'consignor', 'name' => 'Consignor One'],
                [
                    'role' => 'consignee',
                    'name' => 'Consignee One',
                    'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'ph'],
                ],
            ],
            'packages' => [['weight_kg' => 5]],
        ];
    }

    public function test_a_consignee_address_is_stored_as_a_delivery_address_on_the_party(): void
    {
        $company = $this->createCompany('SPA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][1]['address'] += [
            'line2' => 'Unit 5B',
            'state' => 'Metro Manila',
            'postal_code' => '1000',
            'contact_name' => 'Reception desk',
            'contact_phone' => '+63 2 555 0101',
        ];

        $this->actingAs($actor)->post('/shipments', $payload)->assertRedirect();

        $shipment = $this->latestShipment($company);
        $consignee = $shipment->consignee();

        self::assertNotNull($consignee);

        $address = $consignee->address();

        self::assertNotNull($address, 'The consignee address should have been recorded.');
        self::assertSame('24 Mabini Street', $address->line1);
        self::assertSame('Unit 5B', $address->line2);
        self::assertSame('Manila', $address->city);
        self::assertSame('Metro Manila', $address->state);
        self::assertSame('1000', $address->postal_code);
        self::assertSame('Reception desk', $address->contact_name);
        self::assertSame(AddressType::Delivery, $address->type);

        // Country codes are stored upper-case regardless of how they were typed.
        self::assertSame('PH', $address->country_code);
    }

    public function test_a_shipment_cannot_be_booked_without_a_consignee_address(): void
    {
        $company = $this->createCompany('SPB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $payload = $this->basePayload($branch->getKey());
        unset($payload['parties'][1]['address']);

        $this->actingAs($actor)
            ->post('/shipments', $payload)
            ->assertSessionHasErrors([
                'parties.1.address.line1',
                'parties.1.address.city',
                'parties.1.address.country_code',
            ]);
    }

    public function test_the_consignor_address_is_optional_but_must_be_complete_once_started(): void
    {
        $company = $this->createCompany('SPC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        // Omitted entirely: accepted, and no address row is written.
        $this->actingAs($actor)->post('/shipments', $this->basePayload($branch->getKey()))->assertRedirect();

        $consignor = $this->latestShipment($company)->consignor();
        self::assertNotNull($consignor);
        self::assertNull($consignor->address());

        // Half-filled: rejected, because a partial address cannot be collected from.
        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][0]['address'] = ['line2' => 'Warehouse 4'];

        $this->actingAs($actor)
            ->post('/shipments', $payload)
            ->assertSessionHasErrors(['parties.0.address.line1', 'parties.0.address.city']);
    }

    public function test_a_consignor_address_is_stored_as_a_pickup_address(): void
    {
        $company = $this->createCompany('SPD');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][0]['address'] = ['line1' => '12 Al Quoz', 'city' => 'Dubai', 'country_code' => 'AE'];

        $this->actingAs($actor)->post('/shipments', $payload)->assertRedirect();

        $address = $this->latestShipment($company)->consignor()?->address();

        self::assertNotNull($address);
        self::assertSame(AddressType::Pickup, $address->type);
        self::assertSame('Dubai', $address->city);
    }

    public function test_a_party_can_be_linked_to_a_customer_on_file(): void
    {
        $company = $this->createCompany('SPE');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $customer = $this->createCustomer($company, 'Cebu Receiver');

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][1]['customer_id'] = $customer->getKey();

        $this->actingAs($actor)->post('/shipments', $payload)->assertRedirect();

        $consignee = $this->latestShipment($company)->consignee();

        self::assertNotNull($consignee);
        self::assertSame($customer->getKey(), $consignee->customer_id);
        self::assertSame(ShipmentPartyRole::Consignee, $consignee->role);
    }

    public function test_a_party_cannot_be_linked_to_another_companys_customer(): void
    {
        $company = $this->createCompany('SPF');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $otherCompany = $this->createCompany('SPG');
        $foreignCustomer = $this->createCustomer($otherCompany, 'Someone Elses Customer');

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][1]['customer_id'] = $foreignCustomer->getKey();

        $this->actingAs($actor)
            ->post('/shipments', $payload)
            ->assertSessionHasErrors('parties.1.customer_id');
    }

    public function test_the_party_address_is_a_snapshot_and_does_not_follow_the_customer_record(): void
    {
        $company = $this->createCompany('SPH');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $customer = $this->createCustomer($company, 'Cebu Receiver');

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][1]['customer_id'] = $customer->getKey();

        $this->actingAs($actor)->post('/shipments', $payload)->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            // The customer later moves. A shipment already booked must keep the
            // address it was booked with.
            $customer->addresses()->create([
                'type' => AddressType::Delivery,
                'line1' => 'Somewhere Else Entirely',
                'city' => 'Davao',
                'country_code' => 'PH',
            ]);

            $address = Shipment::query()->latest('id')->firstOrFail()->consignee()?->address();

            self::assertNotNull($address);
            self::assertSame('24 Mabini Street', $address->line1);
            self::assertSame('Manila', $address->city);
        } finally {
            $context->forget();
        }
    }

    public function test_a_shipment_must_name_exactly_one_consignor_and_one_consignee(): void
    {
        $company = $this->createCompany('SPI');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][] = ['role' => 'consignor', 'name' => 'A Second Sender'];

        $this->actingAs($actor)
            ->post('/shipments', $payload)
            ->assertSessionHasErrors('parties');
    }

    public function test_a_one_off_consignor_and_consignee_are_saved_as_customers(): void
    {
        $company = $this->createCompany('SPJ');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][0]['company_name'] = 'Virapage';
        $payload['parties'][0]['email'] = 'sender@example.test';
        $payload['parties'][0]['phone'] = '55149142';
        $payload['parties'][1]['email'] = 'receiver@example.test';

        $this->actingAs($actor)->post('/shipments', $payload)->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            $sender = Customer::query()->where('email', 'sender@example.test')->first();
            $receiver = Customer::query()->where('email', 'receiver@example.test')->first();

            self::assertNotNull($sender, 'The one-off consignor should have been saved as a customer.');
            self::assertSame('Consignor One', $sender->name);
            self::assertSame('Virapage', $sender->company_name);
            self::assertSame(CustomerType::Business, $sender->type);

            self::assertNotNull($receiver, 'The one-off consignee should have been saved as a customer.');
            self::assertSame(CustomerType::Individual, $receiver->type);

            $shipment = Shipment::query()->latest('id')->firstOrFail();
            self::assertSame($sender->getKey(), $shipment->consignor()?->customer_id);
            self::assertSame($receiver->getKey(), $shipment->consignee()?->customer_id);
        } finally {
            $context->forget();
        }
    }

    public function test_booking_again_with_the_same_email_reuses_the_customer_instead_of_duplicating_it(): void
    {
        $company = $this->createCompany('SPK');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][1]['email'] = 'repeat-receiver@example.test';

        $this->actingAs($actor)->post('/shipments', $payload)->assertRedirect();
        $this->actingAs($actor)->post('/shipments', $payload)->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            self::assertSame(1, Customer::query()->where('email', 'repeat-receiver@example.test')->count());
        } finally {
            $context->forget();
        }
    }

    public function test_a_partys_contact_details_can_be_corrected_after_booking_regardless_of_shipment_status(): void
    {
        $company = $this->createCompany('SPL');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)->post('/shipments', $this->basePayload($branch->getKey()))->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            $shipment = Shipment::query()->latest('id')->firstOrFail();
            // A delivered shipment is a terminal state ShipmentService::update()
            // would refuse to touch — party corrections must still go through.
            $shipment->forceFill(['status' => 'delivered'])->save();
            $consigneeId = $shipment->consignee()?->getKey();
            self::assertNotNull($consigneeId);
        } finally {
            $context->forget();
        }

        $this->actingAs($actor)
            ->patch("/shipments/{$shipment->getKey()}/parties/{$consigneeId}", [
                'name' => 'Corrected Receiver Name',
                'email' => 'corrected@example.test',
                'phone' => '+63 917 555 0100',
                'address' => ['line1' => '99 New Street', 'city' => 'Cebu', 'country_code' => 'ph'],
            ])
            ->assertRedirect();

        $context->resolveCompany((int) $company->getKey());

        try {
            $consignee = ShipmentParty::query()->with('addresses')->findOrFail($consigneeId);
            self::assertSame('Corrected Receiver Name', $consignee->name);
            self::assertSame('corrected@example.test', $consignee->email);
            self::assertSame('99 New Street', $consignee->address()?->line1);
            self::assertSame('PH', $consignee->address()?->country_code);
        } finally {
            $context->forget();
        }
    }

    public function test_editing_a_party_never_touches_its_linked_customer_record(): void
    {
        $company = $this->createCompany('SPM');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $customer = $this->createCustomer($company, 'Cebu Receiver');

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][1]['customer_id'] = $customer->getKey();
        $this->actingAs($actor)->post('/shipments', $payload)->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            $shipmentId = Shipment::query()->latest('id')->firstOrFail()->getKey();
            $consigneeId = Shipment::query()->findOrFail($shipmentId)->consignee()?->getKey();
            self::assertNotNull($consigneeId);
        } finally {
            $context->forget();
        }

        $this->actingAs($actor)
            ->patch("/shipments/{$shipmentId}/parties/{$consigneeId}", [
                'name' => 'Renamed On This Shipment Only',
                'address' => ['line1' => '24 Mabini Street', 'city' => 'Manila', 'country_code' => 'PH'],
            ])
            ->assertRedirect();

        $context->resolveCompany((int) $company->getKey());

        try {
            self::assertSame('Renamed On This Shipment Only', ShipmentParty::query()->findOrFail($consigneeId)->name);
            self::assertSame($customer->getKey(), ShipmentParty::query()->findOrFail($consigneeId)->customer_id);
            self::assertSame('Cebu Receiver', $customer->fresh()->name);
        } finally {
            $context->forget();
        }
    }

    public function test_a_consignees_address_cannot_be_cleared_but_a_consignors_can(): void
    {
        $company = $this->createCompany('SPN');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $payload = $this->basePayload($branch->getKey());
        $payload['parties'][0]['address'] = ['line1' => '12 Al Quoz', 'city' => 'Dubai', 'country_code' => 'AE'];
        $this->actingAs($actor)->post('/shipments', $payload)->assertRedirect();

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            $shipment = Shipment::query()->with('parties.addresses')->latest('id')->firstOrFail();
            $shipmentId = $shipment->getKey();
            $consignorId = $shipment->consignor()?->getKey();
            $consigneeId = $shipment->consignee()?->getKey();
            self::assertNotNull($consignorId);
            self::assertNotNull($consigneeId);
        } finally {
            $context->forget();
        }

        $this->actingAs($actor)
            ->patch("/shipments/{$shipmentId}/parties/{$consigneeId}", ['name' => 'Consignee One'])
            ->assertSessionHasErrors(['address.line1', 'address.city', 'address.country_code']);

        $this->actingAs($actor)
            ->patch("/shipments/{$shipmentId}/parties/{$consignorId}", ['name' => 'Consignor One'])
            ->assertRedirect();

        $context->resolveCompany((int) $company->getKey());

        try {
            self::assertNull(ShipmentParty::query()->with('addresses')->findOrFail($consignorId)->address());
        } finally {
            $context->forget();
        }
    }

    public function test_a_company_cannot_edit_a_party_on_another_companys_shipment(): void
    {
        $company = $this->createCompany('SPO');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $this->actingAs($actor)->post('/shipments', $this->basePayload($branch->getKey()))->assertRedirect();
        $shipment = $this->latestShipment($company);
        $consignee = $shipment->consignee();

        $otherCompany = $this->createCompany('SPP');
        $otherBranch = $this->createBranch($otherCompany, 'AUH');
        $intruder = $this->createUser($otherCompany, $otherBranch);
        $this->grantPermissions($intruder, ['shipments.view', 'shipments.manage']);

        // CompanyScope filters the {shipment} route-model-binding query
        // itself, so a cross-company request never reaches the policy check
        // at all — it 404s, the same as GET-ing another company's shipment
        // (see ShipmentManagementTest::test_a_company_cannot_view_another_
        // companys_shipment).
        $this->actingAs($intruder)
            ->patch("/shipments/{$shipment->getKey()}/parties/{$consignee->getKey()}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    private function latestShipment(Company $company): Shipment
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return Shipment::query()->with('parties.addresses')->latest('id')->firstOrFail();
        } finally {
            $context->forget();
        }
    }
}
