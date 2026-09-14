<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\TransitionInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Billing\InvoiceTransitionService;
use Illuminate\Http\RedirectResponse;

final class InvoiceTransitionController extends Controller
{
    public function store(TransitionInvoiceRequest $request, Customer $customer, Invoice $invoice, InvoiceTransitionService $transitions): RedirectResponse
    {
        abort_unless($invoice->customer_id === $customer->getKey(), 404);

        $transitions->transition($invoice, InvoiceStatus::from($request->validated('status')), $request->user());

        return back()->with('success', 'Invoice status updated.');
    }
}
