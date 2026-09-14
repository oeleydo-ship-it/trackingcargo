<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehouse;

use App\Enums\WarehouseScanType;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->route('warehouse');

        return $this->user()?->can('scan', [$warehouse, $this->input('scan_type')]) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'to_location_code' => $this->input('to_location_code') === '' ? null : $this->input('to_location_code'),
            'load_unit_id' => $this->input('load_unit_id') === '' ? null : $this->input('load_unit_id'),
        ]);
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        /** @var Warehouse $warehouse */
        $warehouse = $this->route('warehouse');
        $needsLocation = in_array($this->input('scan_type'), [
            WarehouseScanType::Receive->value,
            WarehouseScanType::Sort->value,
            WarehouseScanType::Unload->value,
        ], true);
        $needsLoadUnit = $this->input('scan_type') === WarehouseScanType::Load->value;

        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'scan_type' => ['required', new Enum(WarehouseScanType::class)],
            'package_barcode' => [
                'required',
                'string',
                Rule::exists('shipment_packages', 'barcode')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'to_location_code' => [
                $needsLocation ? 'required' : 'nullable',
                'string',
                Rule::exists('warehouse_locations', 'code')->where(fn ($query) => $query->where('warehouse_id', $warehouse->getKey())),
            ],
            'load_unit_id' => [
                $needsLoadUnit ? 'required' : 'nullable',
                'integer',
                Rule::exists('load_units', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
        ];
    }
}
