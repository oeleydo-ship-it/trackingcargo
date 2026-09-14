<?php

declare(strict_types=1);

namespace App\Http\Requests\Delivery;

use App\Models\Driver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Driver::class) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
                Rule::unique('drivers', 'user_id'),
            ],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'zone_id' => [
                'nullable',
                'integer',
                Rule::exists('delivery_zones', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'license_number' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
