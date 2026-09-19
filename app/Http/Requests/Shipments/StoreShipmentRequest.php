<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Enums\ShipmentMode;
use App\Enums\ShipmentPartyRole;
use App\Models\Shipment;
use App\Services\Shipments\TrackingNumberFormatter;
use App\Services\Tracking\CarrierProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

final class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Shipment::class) ?? false;
    }

    public function rules(): array
    {
        $companyId = app(\App\Tenancy\TenantContext::class)->requireCompanyId();

        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            // Optional grouping for bulk status updates. The batch must be
            // open and in the shipment's branch; ShipmentService enforces both,
            // since it also handles the create-a-batch-inline case.
            'batch_id' => [
                'nullable',
                'integer',
                Rule::exists('shipment_batches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'new_batch_reference' => ['nullable', 'string', 'max:255', 'prohibits:batch_id'],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'mode' => ['required', new Enum(ShipmentMode::class)],
            'carrier_id' => [
                'nullable',
                'integer',
                // Switched-off carriers stay valid on shipments already booked
                // with them, but cannot be picked for new bookings.
                Rule::exists('carriers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')),
            ],
            'carrier_code' => ['nullable', 'string', Rule::in(app(CarrierProviderRegistry::class)->codes())],
            'origin_country_code' => ['nullable', 'string', 'size:2'],
            'destination_country_code' => ['required', 'string', 'size:2'],
            'destination_city' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'size:3'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],

            // Optional manual override, held to the same URL-safe character
            // set as a generated number. The DB unique index on
            // shipments.tracking_number covers soft-deleted rows too, so this
            // check deliberately does not exclude them. Whether an override is
            // accepted at all is the company's call, enforced in ShipmentService.
            'tracking_number' => [
                'nullable',
                'string',
                'max:40',
                'regex:'.TrackingNumberFormatter::SAFE_NUMBER_PATTERN,
                Rule::unique('shipments', 'tracking_number'),
            ],
            'tracking_mode' => ['nullable', Rule::in(['auto', 'suffix', 'full'])],
            'tracking_suffix' => ['required_if:tracking_mode,suffix', 'nullable', 'string', 'max:40', 'regex:'.TrackingNumberFormatter::SAFE_NUMBER_PATTERN, 'prohibits:tracking_number'],

            'parties' => ['required', 'array', 'min:2'],
            'parties.*.role' => ['required', new Enum(ShipmentPartyRole::class)],
            'parties.*.customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'parties.*.name' => ['required', 'string', 'max:255'],
            'parties.*.company_name' => ['nullable', 'string', 'max:255'],
            'parties.*.email' => ['nullable', 'email', 'max:255'],
            'parties.*.phone' => ['nullable', 'string', 'max:40'],
            'parties.*.tax_id' => ['nullable', 'string', 'max:60'],

            // Address fields common to every party. Which of them are required
            // is decided per party by addressRules(), since only the consignee
            // must have one.
            'parties.*.address' => ['nullable', 'array'],
            'parties.*.address.line2' => ['nullable', 'string', 'max:255'],
            'parties.*.address.state' => ['nullable', 'string', 'max:120'],
            'parties.*.address.postal_code' => ['nullable', 'string', 'max:20'],
            'parties.*.address.contact_name' => ['nullable', 'string', 'max:255'],
            'parties.*.address.contact_phone' => ['nullable', 'string', 'max:40'],
            ...$this->addressRules(),

            'packages' => ['required', 'array', 'min:1'],
            'packages.*.pieces' => ['nullable', 'integer', 'min:1', 'max:999'],
            'packages.*.weight_kg' => ['nullable', 'numeric', 'min:0.001'],
            'packages.*.weight_unit' => ['nullable', Rule::in(['kg', 'lb'])],
            'packages.*.box_size_id' => [
                'nullable',
                'integer',
                Rule::exists('box_sizes', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')),
            ],
            'packages.*.length' => ['nullable', 'numeric', 'min:0.01'],
            'packages.*.width' => ['nullable', 'numeric', 'min:0.01'],
            'packages.*.height' => ['nullable', 'numeric', 'min:0.01'],
            'packages.*.dimension_unit' => ['nullable', Rule::in(['cm', 'in'])],
            'packages.*.description' => ['nullable', 'string', 'max:255'],
            'packages.*.declared_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Per-party rules for the three fields that make an address usable.
     *
     * They are built per index rather than with a wildcard because whether
     * they are required depends on that party's own role: the consignee is the
     * one the cargo has to physically reach, so its address is mandatory. For
     * any other party the address is optional, but a half-filled one is not —
     * once a clerk starts typing an address, it has to be complete enough to
     * deliver to.
     *
     * @return array<string, list<string>>
     */
    private function addressRules(): array
    {
        $rules = [];

        foreach ((array) $this->input('parties', []) as $index => $party) {
            $address = is_array($party) ? ($party['address'] ?? []) : [];

            $started = is_array($address) && array_filter(
                $address,
                fn ($value): bool => is_string($value) ? trim($value) !== '' : $value !== null,
            ) !== [];

            $isConsignee = (is_array($party) ? ($party['role'] ?? null) : null) === ShipmentPartyRole::Consignee->value;

            $presence = $isConsignee || $started ? 'required' : 'nullable';

            $rules["parties.{$index}.address.line1"] = [$presence, 'string', 'max:255'];
            $rules["parties.{$index}.address.city"] = [$presence, 'string', 'max:120'];
            $rules["parties.{$index}.address.country_code"] = [$presence, 'string', 'size:2'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        $attributes = [];

        foreach ((array) $this->input('parties', []) as $index => $party) {
            $role = is_array($party) ? ($party['role'] ?? null) : null;
            $label = ShipmentPartyRole::tryFrom((string) $role)?->label() ?? 'party';

            $attributes["parties.{$index}.name"] = mb_strtolower($label).' name';
            $attributes["parties.{$index}.address.line1"] = mb_strtolower($label).' address line 1';
            $attributes["parties.{$index}.address.city"] = mb_strtolower($label).' city';
            $attributes["parties.{$index}.address.country_code"] = mb_strtolower($label).' country';
        }

        return $attributes;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $roles = array_column($this->input('parties', []), 'role');

            if (count(array_filter($roles, fn ($role): bool => $role === ShipmentPartyRole::Consignor->value)) !== 1) {
                $validator->errors()->add('parties', 'A shipment must have exactly one consignor (the sender).');
            }

            if (count(array_filter($roles, fn ($role): bool => $role === ShipmentPartyRole::Consignee->value)) !== 1) {
                $validator->errors()->add('parties', 'A shipment must have exactly one consignee (the receiver).');
            }
        });
    }
}
