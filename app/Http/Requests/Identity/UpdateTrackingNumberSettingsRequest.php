<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Enums\TrackingNumberMode;
use App\Models\Company;
use App\Services\Shipments\TrackingNumberFormatter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Tracking-number preferences live on their own endpoint rather than on
 * UpdateCompanyRequest so each settings card can submit only its own fields
 * instead of every form having to round-trip the other's.
 */
final class UpdateTrackingNumberSettingsRequest extends FormRequest
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
            'tracking_number_format' => ['required', 'string', 'max:60'],
            'tracking_sequence_padding' => ['required', 'integer', 'min:1', 'max:12'],
            'allow_manual_tracking_number' => ['required', 'boolean'],
            // The numbering method the booking form starts with. Optional so a
            // client that only sends the pattern leaves the choice as it was.
            'default_tracking_mode' => ['sometimes', new Enum(TrackingNumberMode::class)],
        ];
    }

    /**
     * The pattern is checked as a whole rather than with a regex rule: an
     * unrecognised token and an unusable literal character are different
     * mistakes and deserve different messages.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('default_tracking_mode') === TrackingNumberMode::Full->value && ! $this->boolean('allow_manual_tracking_number')) {
                $validator->errors()->add('default_tracking_mode', 'Turn on "Allow manual tracking numbers" before making the entire tracking number the default.');
            }

            if ($validator->errors()->has('tracking_number_format')) {
                return;
            }

            $reason = app(TrackingNumberFormatter::class)->invalidFormatReason((string) $this->input('tracking_number_format'));

            if ($reason !== null) {
                $validator->errors()->add('tracking_number_format', $reason);
            }
        });
    }
}
