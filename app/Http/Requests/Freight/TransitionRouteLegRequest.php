<?php

declare(strict_types=1);

namespace App\Http\Requests\Freight;

use App\Enums\RouteLegStatus;
use App\Models\Shipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class TransitionRouteLegRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Shipment $shipment */
        $shipment = $this->route('shipment');

        return $this->user()?->can('transition', $shipment) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(RouteLegStatus::class)],
        ];
    }
}
