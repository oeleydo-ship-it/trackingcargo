<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use App\Enums\CustomsClearanceStatus;
use App\Models\CustomsClearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class TransitionCustomsClearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CustomsClearance $clearance */
        $clearance = $this->route('clearance');

        return $this->user()?->can('transition', $clearance) ?? false;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'status' => ['required', new Enum(CustomsClearanceStatus::class)],
            'reason' => ['nullable', 'required_if:status,held,rejected', 'string', 'max:1000'],
            'next_shipment_status' => ['nullable', Rule::in(['in_transit', 'out_for_delivery'])],
        ];
    }
}
