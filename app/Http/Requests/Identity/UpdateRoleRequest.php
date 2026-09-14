<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Models\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('role')) ?? false;
    }

    /**
     * A system role's slug is a contract, not a label: CustomerPortalService
     * resolves the portal role by the literal slug 'customer', and the seeder
     * re-matches system roles on slug. Renaming one would silently break both,
     * so the submitted slug is ignored for system roles and the name and
     * permissions remain editable.
     */
    protected function prepareForValidation(): void
    {
        /** @var Role $role */
        $role = $this->route('role');

        if ($role->is_system) {
            $this->merge(['slug' => $role->slug]);
        }
    }

    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');
        // See StoreRoleRequest for why this must come from TenantContext.
        $companyId = app(TenantContext::class)->companyId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('roles', 'slug')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($role->getKey())],
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
