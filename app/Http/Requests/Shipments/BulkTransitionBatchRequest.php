<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BulkTransitionBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transition', $this->route('batch')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                // A code from this company's own workflow. Whether the move is
                // legal from where the shipment is now is decided by
                // ShipmentTransitionService against the company's transition
                // rules, which is also the choke point every other caller uses.
                Rule::exists('shipment_statuses', 'code')
                    ->where(fn ($query) => $query->where('company_id', app(\App\Tenancy\TenantContext::class)->requireCompanyId())->where('is_active', true)->whereNull('deleted_at')),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }
}
