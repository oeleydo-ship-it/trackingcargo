<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class StoreStripeSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('system-configuration.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_email' => ['required', 'string', 'email', 'max:255'],
            'price_id' => ['required', 'string', 'max:255', 'regex:/^price_[A-Za-z0-9]+$/'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
