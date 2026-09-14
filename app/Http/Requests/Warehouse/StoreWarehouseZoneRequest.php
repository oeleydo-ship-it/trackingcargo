<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehouse;

use App\Enums\WarehouseZoneType;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreWarehouseZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->route('warehouse');

        return $this->user()?->can('update', $warehouse) ?? false;
    }

    public function rules(): array
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->route('warehouse');

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('warehouse_zones', 'code')->where(fn ($query) => $query->where('warehouse_id', $warehouse->getKey()))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(WarehouseZoneType::class)],
        ];
    }
}
