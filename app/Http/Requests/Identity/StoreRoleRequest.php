<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Models\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    public function rules(): array
    {
        // Not $this->user()->company_id — see StoreBranchRequest for why a
        // platform admin acting as a company needs this from TenantContext.
        $companyId = app(TenantContext::class)->companyId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('roles', 'slug')->where(fn ($query) => $query->where('company_id', $companyId))],
            'permissions' => ['array'],
            'permissions.*' => ['integer', Rule::exists('permissions', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $platformOnlyRequested = Permission::query()
                ->whereIn('id', $this->input('permissions', []))
                ->where('platform_only', true)
                ->exists();

            if ($platformOnlyRequested) {
                $validator->errors()->add('permissions', 'A company role cannot include platform-only permissions.');
            }
        });
    }
}
