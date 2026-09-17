<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Enums\PublicFieldVisibility;
use App\Models\Company;
use App\Services\Shipments\PublicTrackingFieldPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePublicTrackingSettingsRequest extends FormRequest
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
        $levels = array_column(PublicFieldVisibility::cases(), 'value');
        $rules = [];

        // Every role/field pair is required, so a partial post can never leave a
        // field unset and silently fall back to a level the company did not pick.
        foreach ([PublicTrackingFieldPolicy::SENDER, PublicTrackingFieldPolicy::RECEIVER] as $role) {
            foreach (array_keys(PublicTrackingFieldPolicy::FIELDS) as $field) {
                $rules["parties.{$role}.{$field}"] = ['required', Rule::in($levels)];
            }
        }

        return ['parties' => ['required', 'array'], ...$rules];
    }
}
