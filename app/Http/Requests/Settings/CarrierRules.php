<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\ShipmentMode;
use App\Services\Tracking\CarrierProviderRegistry;
use Illuminate\Validation\Rule;

/**
 * Field rules shared by the create and edit carrier forms, which differ only
 * in which row the code must be unique against.
 */
final class CarrierRules
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?int $companyId, ?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('carriers', 'code')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'modes' => ['nullable', 'array'],
            'modes.*' => ['string', Rule::enum(ShipmentMode::class)],
            // Only integrations the platform actually has; anything else would
            // make CarrierTrackingPollService fail on every run.
            'integration_code' => ['nullable', 'string', Rule::in(app(CarrierProviderRegistry::class)->codes())],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
