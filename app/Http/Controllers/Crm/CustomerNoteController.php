<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreCustomerNoteRequest;
use App\Models\Customer;
use App\Models\CustomerNote;
use Illuminate\Http\RedirectResponse;

final class CustomerNoteController extends Controller
{
    public function store(StoreCustomerNoteRequest $request, Customer $customer): RedirectResponse
    {
        $customer->notes()->create([...$request->validated(), 'author_id' => $request->user()?->getKey()]);

        return back()->with('success', 'Note added.');
    }

    public function destroy(Customer $customer, CustomerNote $note): RedirectResponse
    {
        $this->authorize('update', $customer);
        abort_unless($note->customer_id === $customer->getKey(), 404);

        $note->delete();

        return back()->with('success', 'Note removed.');
    }
}
