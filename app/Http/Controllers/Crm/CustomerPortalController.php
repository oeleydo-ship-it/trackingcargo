<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\InviteCustomerPortalRequest;
use App\Models\Customer;
use App\Services\Crm\CustomerPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CustomerPortalController extends Controller
{
    public function store(InviteCustomerPortalRequest $request, Customer $customer, CustomerPortalService $portal): RedirectResponse
    {
        $portal->invite($customer, $request->validated(), $request->user());

        return back()->with('success', 'Portal invitation sent.');
    }

    public function destroy(Request $request, Customer $customer, CustomerPortalService $portal): RedirectResponse
    {
        $this->authorize('update', $customer);

        $portal->unlink($customer, $request->user());

        return back()->with('success', 'Portal account unlinked.');
    }
}
