<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBatchShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('batch')) ?? false;
    }

    public function rules(): array
    {
        $companyId = app(TenantContext::class)->companyId();

        return [
            'shipments' => ['required', 'array', 'min:1'],
            'shipments.*' => [
                'integer',
                Rule::exists('shipments', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
        ];
    }
}
