<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Models\Branch;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('branch')) ?? false;
    }

    public function rules(): array
    {
        /** @var Branch $branch */
        $branch = $this->route('branch');
        // See StoreBranchRequest for why this must come from TenantContext.
        $companyId = app(TenantContext::class)->companyId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($branch->getKey())],
            'tracking_prefix' => ['required', 'string', 'max:10', Rule::unique('branches', 'tracking_prefix')->where(fn ($query) => $query->where('company_id', $companyId))->ignore($branch->getKey())],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country_code' => ['required', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'timezone'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended', 'inactive'])],
            'is_head_office' => ['sometimes', 'boolean'],
        ];
    }
}
