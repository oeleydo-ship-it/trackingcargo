<?php

declare(strict_types=1);

namespace App\Http\Requests\Freight;

use App\Enums\ShipmentMode;
use App\Models\Shipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreRouteLegRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Shipment $shipment */
        $shipment = $this->route('shipment');

        return $this->user()?->can('update', $shipment) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'master_id' => [
                'nullable',
                'integer',
                Rule::exists('masters', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'mode' => ['required', new Enum(ShipmentMode::class)],
            'origin_location' => ['required', 'string', 'max:255'],
            'destination_location' => ['required', 'string', 'max:255'],
            'scheduled_departure_at' => ['nullable', 'date'],
            'scheduled_arrival_at' => ['nullable', 'date', 'after:scheduled_departure_at'],
        ];
    }
}
