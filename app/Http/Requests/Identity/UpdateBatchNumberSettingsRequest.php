<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Models\Company;
use App\Services\Shipments\TrackingNumberFormatter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateBatchNumberSettingsRequest extends FormRequest
{
    /** See UpdateCompanyRequest::authorize() for why this reads TenantContext. */
    public function authorize(): bool
    {
        $companyId = app(TenantContext::class)->companyId();

        if ($companyId === null) {
            return false;
        }

        $company = Company::query()->find($companyId);

        return $company !== null && ($this->user()?->can('update', $company) ?? false);
    }

    public function rules(): array
    {
        return [
            'batch_number_format' => ['required', 'string', 'max:60'],
            'batch_sequence_padding' => ['required', 'integer', 'min:1', 'max:12'],
        ];
    }

    /** @see UpdateTrackingNumberSettingsRequest::withValidator() — same pattern rules. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('batch_number_format')) {
                return;
            }

            $reason = app(TrackingNumberFormatter::class)->invalidFormatReason((string) $this->input('batch_number_format'));

            if ($reason !== null) {
                $validator->errors()->add('batch_number_format', $reason);
            }
        });
    }
}
