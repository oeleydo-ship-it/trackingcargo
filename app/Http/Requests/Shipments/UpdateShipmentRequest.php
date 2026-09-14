<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Enums\ShipmentMode;
use App\Services\Shipments\TrackingNumberFormatter;
use App\Services\Tracking\CarrierProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class UpdateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('shipment')) ?? false;
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
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'mode' => ['required', new Enum(ShipmentMode::class)],
            'carrier_code' => ['nullable', 'string', Rule::in(app(CarrierProviderRegistry::class)->codes())],
            'origin_country_code' => ['nullable', 'string', 'size:2'],
            'destination_country_code' => ['required', 'string', 'size:2'],
            'destination_city' => ['nullable', 'string', 'max:120'],
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
                Rule::unique('shipments', 'tracking_number')->ignore($this->route('shipment')->getKey()),
            ],
        ];
    }
}
