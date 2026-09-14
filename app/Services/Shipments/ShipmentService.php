<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\BatchStatus;
use App\Enums\CustomerType;
use App\Enums\ShipmentPartyRole;
use App\Enums\ShipmentStatusRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\ShipmentParty;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Crm\CustomerService;
use App\Services\Numbering\NumberSequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ShipmentService
{
    private const array AUDITABLE_FIELDS = ['branch_id', 'batch_id', 'customer_id', 'mode', 'carrier_code', 'origin_country_code', 'destination_country_code', 'destination_city', 'declared_value'];

    public function __construct(
        private TenantContext $tenantContext,
        private NumberSequenceService $sequences,
        private ShipmentPackageService $packages,
        private ShipmentBatchService $batches,
        private TrackingNumberFormatter $trackingNumbers,
        private ShipmentStatusRepository $statuses,
        private CustomerService $customers,
        private AuditService $audit,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $parties
     * @param  array<int, array<string, mixed>>  $packages
     */
    public function create(array $data, array $parties, array $packages, User $actor): Shipment
    {
        $companyId = $this->tenantContext->requireCompanyId();

        return DB::transaction(function () use ($data, $parties, $packages, $actor, $companyId): Shipment {
            $company = Company::query()->findOrFail($companyId);
            $branch = Branch::query()->findOrFail($data['branch_id']);

            $batchId = $this->resolveBatch($data, $branch, $actor);

            try {
                $shipment = Shipment::query()->create([
                    ...Arr::only($data, self::AUDITABLE_FIELDS),
                    'batch_id' => $batchId,
                    'tracking_number' => $this->resolveTrackingNumber($company, $branch, $data['tracking_number'] ?? null, $data['tracking_suffix'] ?? null),
                    'status' => $this->statuses->initial($companyId)->code,
                    'currency' => $data['currency'] ?? $company->default_currency,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw ValidationException::withMessages([
                    ! empty($data['tracking_suffix']) ? 'tracking_suffix' : 'tracking_number' => 'This tracking number is already in use. Enter a different reference or try automatic numbering again.',
                ]);
            }

            foreach ($parties as $partyData) {
                $partyData = $this->attachCustomer($partyData, $branch, $actor);

                $party = $shipment->parties()->create(Arr::only($partyData, ['customer_id', 'role', 'name', 'company_name', 'email', 'phone', 'tax_id']));

                $this->syncAddress($party, $partyData['address'] ?? null);
            }

            foreach ($packages as $packageData) {
                $this->packages->add($shipment, $packageData, $company->volumetric_divisor);
            }

            $this->audit->record('shipment.created', $actor, $shipment, newValues: [
                ...$shipment->only(self::AUDITABLE_FIELDS),
                'tracking_number' => $shipment->tracking_number,
            ]);

            return $shipment->fresh(['parties.addresses', 'packages']);
        });
    }

    /**
     * Corrects a booked shipment's own details — destination, carrier,
     * declared value, mode, and so on. Unlike the party contact details
     * updateParty() touches, this used to be refused once a shipment left
     * draft; that guard is gone, since a wrong destination city or carrier
     * is exactly as much of a data-entry mistake worth fixing after booking
     * as a wrong phone number is. branch_id stays out of AUDITABLE_FIELDS'
     * effective reach here in practice — the booking form never offers a
     * branch picker on this form — because the branch is baked into the
     * tracking number's own prefix and every batch it can join; reassigning
     * it after the fact is a different, unimplemented operation, not a
     * correction.
     */
    public function update(Shipment $shipment, array $data, User $actor): Shipment
    {
        $fields = Arr::only($data, self::AUDITABLE_FIELDS);

        // Renumbering stays restricted to draft/initial regardless of the
        // above: past that point the number is on printed labels and
        // already quoted to the customer, so changing it would desync the
        // shipment from what's physically on the box.
        $manual = $this->normalizeManual($data['tracking_number'] ?? null);

        if ($manual !== null && $manual !== $shipment->tracking_number) {
            if (! $this->canRenumber($shipment)) {
                throw ValidationException::withMessages(['tracking_number' => 'The tracking number can only be changed while the shipment is still in its initial status.']);
            }

            $company = Company::query()->findOrFail($this->tenantContext->requireCompanyId());
            $this->assertManualAllowed($company);
            $fields['tracking_number'] = $manual;
        }

        $oldValues = $shipment->only(array_keys($fields));

        $shipment->fill($fields)->save();

        $this->audit->record('shipment.updated', $actor, $shipment, oldValues: $oldValues, newValues: $fields);

        return $shipment;
    }

    /**
     * Corrects a party's contact details and address after booking.
     *
     * Not gated by shipment status, same as update() now isn't either: a
     * wrong phone number or a misspelled street name is a data-entry
     * correction, not a change to what was shipped, and holding it hostage
     * until the shipment reaches a terminal state (or never, since a
     * delivered shipment can't go back to draft) would make typos
     * permanent. customer_id is intentionally left alone — see
     * ShipmentPartyTest::test_the_party_address_is_a_snapshot_and_does_not_
     * follow_the_customer_record for why the party's own fields are already
     * a booking-time snapshot independent of the linked customer, if any.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateParty(ShipmentParty $party, array $data, User $actor): ShipmentParty
    {
        $fields = Arr::only($data, ['name', 'company_name', 'email', 'phone', 'tax_id']);
        $oldValues = $party->only(array_keys($fields));

        $party->fill($fields)->save();
        $this->syncAddress($party, $data['address'] ?? null);

        $this->audit->record('shipment.party-updated', $actor, $party, oldValues: $oldValues, newValues: $fields);

        return $party->fresh('addresses');
    }

    /**
     * Soft-deletes a shipment booked by mistake. Left unrestricted by
     * status deliberately — same reasoning as update() and updateParty()
     * above — but it is a soft delete precisely because a shipment this
     * far along commonly has packages, tracking events, invoices, and
     * customs records hanging off it that a hard delete would either
     * orphan or cascade away; a soft-deleted row keeps all of that intact
     * and out of the tenant's day-to-day lists.
     */
    public function delete(Shipment $shipment, User $actor): void
    {
        $shipment->delete();

        $this->audit->record('shipment.deleted', $actor, $shipment, oldValues: $shipment->only(self::AUDITABLE_FIELDS));
    }

    /**
     * Whether the tracking number is still safe to change: nothing has been
     * printed or handed to the customer while the shipment sits in its
     * company-defined starting status.
     */
    private function canRenumber(Shipment $shipment): bool
    {
        return $shipment->hasStatusRole(ShipmentStatusRole::Draft) || $shipment->shipmentStatus?->is_initial === true;
    }

    /**
     * Turns a one-off consignor/consignee typed on the booking form into a
     * customer record, so the next shipment to or from the same person can be
     * found by name instead of retyped from scratch.
     *
     * Only the two cargo-side roles qualify — a notify party is cc'd on
     * updates, not someone the company ships to or from, so it stays a
     * shipment-only contact. A party already linked via "Customer on file" is
     * left untouched. Matching an existing customer by email or phone is a
     * best-effort heuristic to avoid piling up duplicates for a repeat
     * customer who books as a one-off every time; it can occasionally match
     * the wrong record (e.g. a shared office line), which is why it only
     * reuses an existing customer rather than overwriting one.
     *
     * @param  array<string, mixed>  $partyData
     * @return array<string, mixed>
     */
    private function attachCustomer(array $partyData, Branch $branch, User $actor): array
    {
        $role = ShipmentPartyRole::tryFrom((string) ($partyData['role'] ?? ''));

        if (($partyData['customer_id'] ?? null) !== null || ! in_array($role, [ShipmentPartyRole::Consignor, ShipmentPartyRole::Consignee], true)) {
            return $partyData;
        }

        $name = trim((string) ($partyData['name'] ?? ''));

        if ($name === '') {
            return $partyData;
        }

        $email = trim((string) ($partyData['email'] ?? '')) ?: null;
        $phone = trim((string) ($partyData['phone'] ?? '')) ?: null;

        $existing = $email !== null || $phone !== null
            ? Customer::query()
                ->when($email, fn ($query) => $query->orWhere('email', $email))
                ->when($phone, fn ($query) => $query->orWhere('phone', $phone))
                ->first()
            : null;

        if ($existing !== null) {
            $partyData['customer_id'] = $existing->getKey();

            return $partyData;
        }

        $customer = $this->customers->create([
            'branch_id' => $branch->getKey(),
            'type' => empty($partyData['company_name']) ? CustomerType::Individual->value : CustomerType::Business->value,
            'name' => $name,
            'company_name' => $partyData['company_name'] ?? null,
            'email' => $email,
            'phone' => $phone,
            'tax_id' => $partyData['tax_id'] ?? null,
            'address' => $partyData['address'] ?? null,
        ], $actor);

        $partyData['customer_id'] = $customer->getKey();

        return $partyData;
    }

    /**
     * Records where a party physically is: the pickup point for a consignor,
     * the delivery point for a consignee.
     *
     * The address is stored against the party rather than the shipment because
     * it is that party's own address, and it is copied rather than referenced
     * so that later edits to a customer's address book cannot rewrite the
     * destination of a shipment already in transit.
     *
     * Shared by create() (where the party never already has one) and
     * updateParty() (where it may): a blank submission clears an existing
     * address rather than leaving a stale one in place, and a non-blank one
     * updates in place instead of piling up a second address row.
     *
     * @param  array<string, mixed>|null  $address
     */
    private function syncAddress(ShipmentParty $party, ?array $address): void
    {
        $fields = Arr::only($address ?? [], [
            'line1', 'line2', 'city', 'state', 'postal_code', 'country_code', 'contact_name', 'contact_phone',
        ]);

        $fields = array_filter(
            array_map(fn ($value) => is_string($value) ? trim($value) : $value, $fields),
            fn ($value): bool => $value !== null && $value !== '',
        );

        $existing = $party->address();

        // An optional address left blank on the form arrives as a set of empty
        // strings rather than as no address at all.
        if (($fields['line1'] ?? '') === '') {
            $existing?->delete();

            return;
        }

        $payload = [
            ...$fields,
            'country_code' => mb_strtoupper((string) $fields['country_code']),
            'type' => $party->role->addressType(),
            'label' => $party->role->label(),
            'is_default' => true,
        ];

        if ($existing !== null) {
            $existing->fill($payload)->save();
        } else {
            $party->addresses()->create($payload);
        }
    }

    /**
     * Resolves the batch a new shipment joins: an existing open batch, a batch
     * created on the spot from the booking form, or none.
     *
     * A batch is branch-owned, so it must be in the same branch as the shipment
     * — see ShipmentBatchService::addShipments() for the same rule on the other
     * path into a batch.
     */
    private function resolveBatch(array $data, Branch $branch, User $actor): ?int
    {
        $reference = trim((string) ($data['new_batch_reference'] ?? ''));

        if ($reference !== '') {
            return (int) $this->batches->create([
                'branch_id' => $branch->getKey(),
                'reference' => $reference,
            ], $actor)->getKey();
        }

        if (($data['batch_id'] ?? null) === null) {
            return null;
        }

        $batch = ShipmentBatch::query()->findOrFail($data['batch_id']);

        if ($batch->branch_id !== $branch->getKey()) {
            throw ValidationException::withMessages([
                'batch_id' => "Batch {$batch->batch_number} belongs to a different branch than this shipment.",
            ]);
        }

        if ($batch->status !== BatchStatus::Open) {
            throw ValidationException::withMessages([
                'batch_id' => "Batch {$batch->batch_number} is closed.",
            ]);
        }

        return (int) $batch->getKey();
    }

    /**
     * A manually supplied number wins when the company allows one; otherwise
     * the number is allocated from the company's configured pattern.
     */
    private function resolveTrackingNumber(Company $company, Branch $branch, mixed $manual, mixed $suffix = null): string
    {
        $suffix = $this->normalizeManual($suffix);
        if ($suffix !== null) {
            $number = $this->trackingNumbers->render(
                str_replace('{sequence}', strtoupper($suffix), $this->trackingNumbers->format($company)),
                $company, $branch, null, $this->trackingNumbers->padding($company),
            );
            if (strlen($number) > 40 || ! $this->trackingNumbers->isUrlSafe($number)) {
                throw ValidationException::withMessages(['tracking_suffix' => 'The complete tracking number must be at most 40 characters and use letters, digits, dashes, underscores or dots.']);
            }
            if (Shipment::withoutGlobalScopes()->where('tracking_number', $number)->exists()) {
                throw ValidationException::withMessages(['tracking_suffix' => 'This tracking number is already in use. Enter a different receipt or reference number.']);
            }

            return $number;
        }
        $manual = $this->normalizeManual($manual);

        if ($manual !== null) {
            $this->assertManualAllowed($company);

            return $manual;
        }

        return $this->allocateTrackingNumber($company, $branch);
    }

    private function allocateTrackingNumber(Company $company, Branch $branch): string
    {
        $format = $this->trackingNumbers->format($company);
        $period = $this->trackingNumbers->period($format);
        do {
            $sequence = $this->sequences->next('shipment', (int) $branch->getKey(), $period);
            $trackingNumber = $this->trackingNumbers->render($format, $company, $branch, $sequence, $this->trackingNumbers->padding($company));
        } while (Shipment::withoutGlobalScopes()->where('tracking_number', $trackingNumber)->exists());

        // A pattern saved before the settings form validated it — or one whose
        // branch prefix carries stray characters — can still render a number
        // that breaks the public tracking URL. Fail loudly here rather than
        // persist a shipment nobody can look up.
        if (! $this->trackingNumbers->isUrlSafe($trackingNumber)) {
            throw ValidationException::withMessages([
                'tracking_number' => "The tracking number pattern produced \"{$trackingNumber}\", which is not a usable tracking number. Fix the format under Settings → Tracking numbers.",
            ]);
        }

        return $trackingNumber;
    }

    private function normalizeManual(mixed $manual): ?string
    {
        if (! is_string($manual)) {
            return null;
        }

        $manual = trim($manual);

        return $manual === '' ? null : $manual;
    }

    private function assertManualAllowed(Company $company): void
    {
        if (! $company->allow_manual_tracking_number) {
            throw ValidationException::withMessages([
                'tracking_number' => 'This company does not allow manually entered tracking numbers.',
            ]);
        }
    }
}
