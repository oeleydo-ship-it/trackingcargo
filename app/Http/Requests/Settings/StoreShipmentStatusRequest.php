<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\ShipmentStatus;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ShipmentStatus::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Adds the status to that branch's own workflow instead of the
            // company default.
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', app(TenantContext::class)->companyId())->whereNull('deleted_at')),
            ],
            'color' => ['required', Rule::in(ShipmentStatusColors::ALL)],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_public' => ['sometimes', 'boolean'],
            'is_terminal' => ['sometimes', 'boolean'],
            'is_initial' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],

            // A new status carries no system role: the roles exist so the
            // built-in modules can find their anchors, and each is already
            // taken by exactly one status.
            'transitions_to' => ['sometimes', 'array'],
            'transitions_to.*' => [
                'integer',
                Rule::exists('shipment_statuses', 'id')
                    ->where(fn ($query) => $query->where('company_id', $this->user()?->company_id)->whereNull('deleted_at')),
            ],
        ];
    }
}
