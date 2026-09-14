<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\ShipmentMode;
use App\Models\RateCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreRateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RateCard::class) ?? false;
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
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'mode' => ['nullable', new Enum(ShipmentMode::class)],
            'currency' => ['required', 'string', 'size:3'],
            'base_fee' => ['nullable', 'numeric', 'min:0'],
            'min_charge' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
