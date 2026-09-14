<?php

declare(strict_types=1);

namespace App\Http\Requests\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCodRemittanceRequest extends FormRequest
{
    /**
     * A dispatcher/cashier (billing.manage) can record a remittance for any
     * driver in the company; a driver (deliveries.execute) may only record
     * their own — the same "specific individual, not just a permission"
     * shape as DeliveryAssignmentPolicy::execute() from Phase 7.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($user->hasPermission('billing.manage')) {
            return true;
        }

        return $user->hasPermission('deliveries.execute')
            && $user->driver !== null
            && $user->driver->getKey() === $this->input('driver_id');
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'driver_id' => [
                'required',
                'integer',
                Rule::exists('drivers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
