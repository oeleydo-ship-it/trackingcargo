<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\ShipmentMode;
use App\Models\Company;
use App\Services\Shipments\TrackingNumberFormatter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class TrackingNumberFormatRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = app(TenantContext::class)->companyId();
        $company = $companyId !== null ? Company::query()->find($companyId) : null;

        return $company !== null && ($this->user()?->can('update', $company) ?? false);
    }

    public function rules(): array
    {
        $companyId = app(TenantContext::class)->companyId();
        $ruleId = $this->route('trackingNumberFormat')?->getKey();

        return [
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'mode' => ['nullable', Rule::enum(ShipmentMode::class)],
            'format' => ['required', 'string', 'max:120'],
            'sequence_padding' => ['required', 'integer', 'min:1', 'max:12'],

            // One rule per branch-and-mode combination, so resolving a format
            // never has two equally specific answers.
            'combination' => [
                Rule::unique('tracking_number_formats', 'mode_key')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('scope', (int) ($this->input('branch_id') ?? 0)))
                    ->ignore($ruleId),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // Upper-cased outside the tokens only: {sequence} typed as
            // {SEQUENCE} is not a token and would land in the number literally.
            'format' => (string) preg_replace_callback(
                '/\{[^{}]*\}|[^{}]+/',
                fn (array $match): string => str_starts_with($match[0], '{') ? strtolower($match[0]) : strtoupper($match[0]),
                trim((string) $this->input('format')),
            ),
            // Only ever validated, never stored: it stands in for the
            // branch-and-mode pair the unique rule above checks.
            'combination' => $this->input('mode') ?: '*',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('format')) {
                return;
            }

            $problem = app(TrackingNumberFormatter::class)->invalidFormatReason((string) $this->input('format'));

            if ($problem !== null) {
                $validator->errors()->add('format', $problem);
            }
        });
    }

    public function messages(): array
    {
        return [
            'combination.unique' => 'There is already a format for that branch and mode. Edit that one instead.',
        ];
    }
}
