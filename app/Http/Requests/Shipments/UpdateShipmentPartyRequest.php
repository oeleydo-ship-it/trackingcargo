<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Enums\ShipmentPartyRole;
use App\Models\ShipmentParty;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateShipmentPartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('shipment')) ?? false;
    }

    public function rules(): array
    {
        /** @var ShipmentParty $party */
        $party = $this->route('party');

        $address = $this->input('address', []);
        $started = is_array($address) && array_filter(
            $address,
            fn ($value): bool => is_string($value) ? trim($value) !== '' : $value !== null,
        ) !== [];

        // Same rule as booking: the consignee's address is mandatory since
        // that's where the cargo has to physically reach; any other party's
        // is optional, but a half-filled one is rejected rather than saved.
        $presence = $party->role === ShipmentPartyRole::Consignee || $started ? 'required' : 'nullable';

        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'tax_id' => ['nullable', 'string', 'max:60'],

            'address' => ['nullable', 'array'],
            'address.line1' => [$presence, 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.city' => [$presence, 'string', 'max:120'],
            'address.state' => ['nullable', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.country_code' => [$presence, 'string', 'size:2'],
            'address.contact_name' => ['nullable', 'string', 'max:255'],
            'address.contact_phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
