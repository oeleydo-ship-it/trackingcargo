<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PlatformSetting;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\InvoiceTransitionMap;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class InvoiceController extends Controller
{
    public function show(Customer $customer, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);
        abort_unless($invoice->customer_id === $customer->getKey(), 404);

        $invoice->load(['items.shipment:id,tracking_number', 'payments.actor:id,name']);

        return Inertia::render('Billing/Invoices/Show', [
            'customer' => ['id' => $customer->getKey(), 'name' => $customer->name, 'customer_number' => $customer->customer_number],
            'invoice' => $invoice,
            'allowedTransitions' => array_map(
                fn ($status) => ['value' => $status->value, 'label' => $status->label()],
                InvoiceTransitionMap::allowedFrom($invoice->status),
            ),
            'stripe_ready' => (bool) PlatformSetting::current()->stripe_secret_key,
        ]);
    }

    public function store(StoreInvoiceRequest $request, Customer $customer, InvoiceService $invoices): RedirectResponse
    {
        $invoice = $invoices->create($customer, $request->validated(), $request->user());

        return to_route('crm.customers.invoices.show', [$customer, $invoice])->with('success', "Invoice {$invoice->invoice_number} created.");
    }
}
