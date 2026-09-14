<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use App\Models\CustomsClearance;
use App\Models\Shipment;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCustomsClearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Shipment $shipment */
        $shipment = $this->route('shipment');

        return $this->user()?->can('create', [CustomsClearance::class, $shipment]) ?? false;
    }

    public function rules(): array
    {
        return [
            'declaration_number' => ['nullable', 'string', 'max:60'],
            'customs_office' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
