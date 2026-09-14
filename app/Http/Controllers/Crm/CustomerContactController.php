<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreCustomerContactRequest;
use App\Http\Requests\Crm\UpdateCustomerContactRequest;
use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Http\RedirectResponse;

final class CustomerContactController extends Controller
{
    public function store(StoreCustomerContactRequest $request, Customer $customer): RedirectResponse
    {
        $customer->contacts()->create($request->validated());

        return back()->with('success', 'Contact added.');
    }

    public function update(UpdateCustomerContactRequest $request, Customer $customer, CustomerContact $contact): RedirectResponse
    {
        abort_unless($contact->customer_id === $customer->getKey(), 404);

        $contact->fill($request->validated())->save();

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Customer $customer, CustomerContact $contact): RedirectResponse
    {
        $this->authorize('update', $customer);
        abort_unless($contact->customer_id === $customer->getKey(), 404);

        $contact->delete();

        return back()->with('success', 'Contact removed.');
    }
}
