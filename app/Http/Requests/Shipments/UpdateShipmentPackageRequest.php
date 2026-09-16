<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Models\Shipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateShipmentPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Shipment $shipment */
        $shipment = $this->route('shipment');

        return $this->user()?->can('update', $shipment) ?? false;
    }

    public function rules(): array
    {
        $companyId = app(\App\Tenancy\TenantContext::class)->requireCompanyId();

        return [
            'pieces' => ['nullable', 'integer', 'min:1', 'max:999'],
            'weight_kg' => ['required', 'numeric', 'min:0.001'],
            'weight_unit' => ['nullable', Rule::in(['kg', 'lb'])],
            'box_size_id' => [
                'nullable',
                'integer',
                Rule::exists('box_sizes', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')),
            ],
            'length' => ['nullable', 'numeric', 'min:0.01'],
            'width' => ['nullable', 'numeric', 'min:0.01'],
            'height' => ['nullable', 'numeric', 'min:0.01'],
            'dimension_unit' => ['nullable', Rule::in(['cm', 'in'])],
            'description' => ['nullable', 'string', 'max:255'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
