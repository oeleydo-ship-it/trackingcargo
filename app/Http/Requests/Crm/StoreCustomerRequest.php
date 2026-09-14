<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Enums\AddressType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'type' => ['required', new Enum(CustomerType::class)],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'tax_id' => ['nullable', 'string', 'max:60'],
            'identification_number' => ['nullable', 'string', 'max:60'],
            'status' => ['sometimes', new Enum(CustomerStatus::class)],

            // An optional first address, so a customer can be captured in one
            // pass at the counter instead of saving and then adding one from
            // the customer page. Further addresses go through
            // CustomerAddressController as before.
            'address' => ['nullable', 'array'],
            'address.type' => ['nullable', new Enum(AddressType::class)],
            'address.label' => ['nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.state' => ['nullable', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.contact_name' => ['nullable', 'string', 'max:255'],
            'address.contact_phone' => ['nullable', 'string', 'max:40'],
            ...$this->addressRules(),
        ];
    }

    /**
     * The three fields that make an address usable. Blank throughout means no
     * address was offered and none is written; but once any field is filled
     * the address has to be complete enough to actually reach.
     *
     * @return array<string, list<string>>
     */
    private function addressRules(): array
    {
        $address = $this->input('address');

        $started = is_array($address) && array_filter(
            $address,
            fn ($value): bool => is_string($value) ? trim($value) !== '' : $value !== null,
        ) !== [];

        $presence = $started ? 'required' : 'nullable';

        return [
            'address.line1' => [$presence, 'string', 'max:255'],
            'address.city' => [$presence, 'string', 'max:120'],
            'address.country_code' => [$presence, 'string', 'size:2'],
        ];
    }
}
