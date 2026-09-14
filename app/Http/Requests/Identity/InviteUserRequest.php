<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    public function rules(): array
    {
        // See StoreBranchRequest for why this must come from TenantContext,
        // not $this->user()->company_id — otherwise a platform admin acting
        // as a company could invite a user with a branch_id from any company
        // (the exists-check below would scope to company_id = null, i.e.
        // nothing, so the branch_id check would only ever reject, not scope).
        $companyId = app(TenantContext::class)->companyId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
        ];
    }
}
