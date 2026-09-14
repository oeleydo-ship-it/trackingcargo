<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCustomerNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return $this->user()?->can('update', $customer) ?? false;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }
}
