<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Models\ShipmentBatch;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreShipmentBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ShipmentBatch::class) ?? false;
    }

    public function rules(): array
    {
        // See StoreBranchRequest for why the company comes from TenantContext.
        $companyId = app(TenantContext::class)->companyId();

        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
