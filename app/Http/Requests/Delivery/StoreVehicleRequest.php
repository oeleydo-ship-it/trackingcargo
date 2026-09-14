<?php

declare(strict_types=1);

namespace App\Http\Requests\Delivery;

use App\Enums\VehicleType;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Vehicle::class) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'registration_number' => ['required', 'string', 'max:40', Rule::unique('vehicles', 'registration_number')->where(fn ($query) => $query->where('company_id', $companyId))],
            'type' => ['required', new Enum(VehicleType::class)],
            'capacity_kg' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
