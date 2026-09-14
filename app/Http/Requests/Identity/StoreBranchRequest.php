<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Models\Branch;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Branch::class) ?? false;
    }

    public function rules(): array
    {
        // Not $this->user()->company_id: for a platform admin acting as a
        // company (see ResolveTenant::resolvePlatformAdmin()) that's always
        // null, which would silently scope this uniqueness check to nothing
        // and let them create a branch code that collides with a real one in
        // the company they're actually acting on.
        $companyId = app(TenantContext::class)->companyId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')->where(fn ($query) => $query->where('company_id', $companyId))],
            'tracking_prefix' => ['required', 'string', 'max:10', Rule::unique('branches', 'tracking_prefix')->where(fn ($query) => $query->where('company_id', $companyId))],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country_code' => ['required', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'timezone'],
            'is_head_office' => ['sometimes', 'boolean'],
        ];
    }
}
