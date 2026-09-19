<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Enums\TrackingNumberMode;
use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * One branch's default numbering method, or blank to follow the company's.
 * Managed by whoever manages the company's tracking-number settings.
 */
final class UpdateBranchTrackingModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->company();

        return $company !== null && ($this->user()?->can('update', $company) ?? false);
    }

    public function rules(): array
    {
        return [
            'default_tracking_mode' => ['nullable', new Enum(TrackingNumberMode::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('default_tracking_mode') === TrackingNumberMode::Full->value && ! $this->company()?->allow_manual_tracking_number) {
                $validator->errors()->add('default_tracking_mode', 'Turn on "Allow manual tracking numbers" for the company before making the entire tracking number this branch\'s default.');
            }
        });
    }

    private function company(): ?Company
    {
        $companyId = app(TenantContext::class)->companyId();

        return $companyId === null ? null : Company::query()->find($companyId);
    }
}
