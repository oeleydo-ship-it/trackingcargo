<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreInvoiceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return $this->user()?->can('update', $invoice) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $isFromShipment = $this->boolean('from_shipment');

        return [
            'from_shipment' => ['required', 'boolean'],
            'shipment_id' => [
                $isFromShipment ? 'required' : 'nullable',
                'integer',
                Rule::exists('shipments', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'rate_card_id' => [
                $isFromShipment ? 'required' : 'prohibited',
                'integer',
                Rule::exists('rate_cards', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'description' => [$isFromShipment ? 'prohibited' : 'required', 'string', 'max:255'],
            'quantity' => [$isFromShipment ? 'prohibited' : 'required', 'numeric', 'min:0.001'],
            'unit_price' => [$isFromShipment ? 'prohibited' : 'required', 'numeric', 'min:0'],
        ];
    }
}
