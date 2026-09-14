<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompanyRequest extends FormRequest
{
    /**
     * Reads the company to authorize against from TenantContext rather than
     * $this->user()->company: for an ordinary user these are always the same
     * company, but a platform admin has no company of their own at all — see
     * ResolveTenant::resolvePlatformAdmin(). Without an acting company
     * selected, TenantContext::companyId() is null and this correctly
     * returns false (nothing to update) rather than throwing.
     */
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
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['required', 'timezone'],
            'default_currency' => ['required', 'string', 'size:3'],
        ];
    }
}
