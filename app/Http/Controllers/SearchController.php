<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A single small JSON endpoint (not an Inertia page — the UI is a
 * type-ahead dropdown, not a navigation) searching across the handful of
 * models a user might actually be looking someone/something up by. Each
 * category is independently gated by the same permission the corresponding
 * index page already requires, and a customer portal login is restricted
 * to its own records exactly like ShipmentController::index() and
 * InvoicePolicy — see the Phase 10 security review for why that check
 * cannot be skipped here.
 */
final class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));

        if ($user === null || mb_strlen($term) < 2) {
            return response()->json(['shipments' => [], 'customers' => [], 'invoices' => []]);
        }

        $shipments = [];
        if ($user->hasPermission('shipments.view')) {
            $shipments = Shipment::query()
                ->when($user->customerProfile !== null, fn ($query) => $query->where('customer_id', $user->customerProfile->getKey()))
                ->when(
                    $user->customerProfile === null && $user->branch_id !== null && ! $user->hasPermission('shipments.manage'),
                    fn ($query) => $query->where('branch_id', $user->branch_id),
                )
                ->where('tracking_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->limit(8)
                ->get(['id', 'tracking_number', 'destination_city'])
                ->map(fn (Shipment $shipment): array => [
                    'id' => $shipment->getKey(),
                    'label' => $shipment->tracking_number,
                    'sublabel' => $shipment->destination_city,
                    'url' => "/shipments/{$shipment->getKey()}",
                ]);
        }

        $customers = [];
        if ($user->hasPermission('customers.view') && $user->customerProfile === null) {
            $customers = Customer::query()
                ->search($term)
                ->limit(8)
                ->get(['id', 'name', 'customer_number'])
                ->map(fn (Customer $customer): array => [
                    'id' => $customer->getKey(),
                    'label' => $customer->name,
                    'sublabel' => $customer->customer_number,
                    'url' => "/crm/customers/{$customer->getKey()}",
                ]);
        }

        $invoices = [];
        if ($user->hasPermission('billing.view')) {
            $invoices = Invoice::query()
                ->when($user->customerProfile !== null, fn ($query) => $query->where('customer_id', $user->customerProfile->getKey()))
                ->where('invoice_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->limit(8)
                ->get(['id', 'customer_id', 'invoice_number', 'total', 'currency'])
                ->map(fn (Invoice $invoice): array => [
                    'id' => $invoice->getKey(),
                    'label' => $invoice->invoice_number,
                    'sublabel' => "{$invoice->currency} {$invoice->total}",
                    'url' => "/crm/customers/{$invoice->customer_id}/invoices/{$invoice->getKey()}",
                ]);
        }

        return response()->json(['shipments' => $shipments, 'customers' => $customers, 'invoices' => $invoices]);
    }
}
