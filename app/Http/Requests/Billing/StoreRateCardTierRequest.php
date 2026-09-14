<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\RateCard;
use Illuminate\Foundation\Http\FormRequest;

final class StoreRateCardTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RateCard $rateCard */
        $rateCard = $this->route('rateCard');

        return $this->user()?->can('update', $rateCard) ?? false;
    }

    public function rules(): array
    {
        return [
            'min_weight_kg' => ['required', 'numeric', 'min:0'],
            'max_weight_kg' => ['nullable', 'numeric', 'gt:min_weight_kg'],
            'price_per_kg' => ['required', 'numeric', 'min:0'],
        ];
    }
}
