<?php

declare(strict_types=1);

namespace App\Http\Requests\Delivery;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Vehicle $vehicle */
        $vehicle = $this->route('vehicle');

        return $this->user()?->can('update', $vehicle) ?? false;
    }

    public function rules(): array
    {
        return [
            'capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', new Enum(VehicleStatus::class)],
        ];
    }
}
