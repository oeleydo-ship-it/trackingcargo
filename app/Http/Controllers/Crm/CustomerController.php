<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreCustomerRequest;
use App\Http\Requests\Crm\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\Crm\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $search = $request->string('q')->trim()->toString();

        return Inertia::render('Crm/Customers/Index', [
            'customers' => Customer::query()
                ->with('branch:id,name')
                ->when($search !== '', fn ($query) => $query->search($search))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'filters' => ['q' => $search],
        ]);
    }

    public function show(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        $customer->load(['branch:id,name', 'portalUser:id,name,email', 'contacts', 'addresses', 'notes.author:id,name', 'invoices']);

        return Inertia::render('Crm/Customers/Show', ['customer' => $customer]);
    }

    public function store(StoreCustomerRequest $request, CustomerService $customers): RedirectResponse
    {
        $customer = $customers->create($request->validated(), $request->user());

        return to_route('crm.customers.show', $customer)->with('success', 'Customer created.');
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, CustomerService $customers): RedirectResponse
    {
        $customers->update($customer, $request->validated(), $request->user());

        return back()->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $customer->delete();

        return to_route('crm.customers.index')->with('success', 'Customer removed.');
    }
}
