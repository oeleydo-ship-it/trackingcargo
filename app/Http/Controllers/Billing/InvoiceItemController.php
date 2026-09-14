<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreInvoiceItemRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RateCard;
use App\Models\Shipment;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\RedirectResponse;

final class InvoiceItemController extends Controller
{
    public function store(StoreInvoiceItemRequest $request, Customer $customer, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        abort_unless($invoice->customer_id === $customer->getKey(), 404);

        $data = $request->validated();

        if ($data['from_shipment']) {
            $shipment = Shipment::query()->findOrFail($data['shipment_id']);
            $rateCard = RateCard::query()->findOrFail($data['rate_card_id']);
            $invoices->addShipmentItem($invoice, $shipment, $rateCard, $request->user());
        } else {
            $invoices->addManualItem($invoice, $data, $request->user());
        }

        return back()->with('success', 'Line item added.');
    }

    public function destroy(Customer $customer, Invoice $invoice, InvoiceItem $item, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('update', $invoice);
        abort_unless($invoice->customer_id === $customer->getKey() && $item->invoice_id === $invoice->getKey(), 404);

        $invoices->removeItem($invoice, $item, request()->user());

        return back()->with('success', 'Line item removed.');
    }
}
