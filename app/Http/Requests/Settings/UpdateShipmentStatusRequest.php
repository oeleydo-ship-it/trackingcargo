<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('shipmentStatus')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', Rule::in(ShipmentStatusColors::ALL)],
            'sequence' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_public' => ['sometimes', 'boolean'],
            'is_terminal' => ['sometimes', 'boolean'],
            'is_initial' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],

            'transitions_to' => ['sometimes', 'array'],
            'transitions_to.*' => [
                'integer',
                Rule::exists('shipment_statuses', 'id')
                    ->where(fn ($query) => $query->where('company_id', $this->user()?->company_id)->whereNull('deleted_at')),
            ],
        ];
    }
}
