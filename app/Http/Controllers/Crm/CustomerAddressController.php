<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreAddressRequest;
use App\Http\Requests\Crm\UpdateAddressRequest;
use App\Models\Address;
use App\Models\Customer;
use App\Services\Crm\AddressService;
use Illuminate\Http\RedirectResponse;

final class CustomerAddressController extends Controller
{
    public function store(StoreAddressRequest $request, Customer $customer, AddressService $addresses): RedirectResponse
    {
        $addresses->create($customer, $request->validated());

        return back()->with('success', 'Address added.');
    }

    public function update(UpdateAddressRequest $request, Customer $customer, Address $address, AddressService $addresses): RedirectResponse
    {
        abort_unless($address->addressable_type === Customer::class && $address->addressable_id === $customer->getKey(), 404);

        $addresses->update($address, $request->validated());

        return back()->with('success', 'Address updated.');
    }

    public function destroy(Customer $customer, Address $address): RedirectResponse
    {
        $this->authorize('update', $customer);
        abort_unless($address->addressable_type === Customer::class && $address->addressable_id === $customer->getKey(), 404);

        $address->delete();

        return back()->with('success', 'Address removed.');
    }
}
