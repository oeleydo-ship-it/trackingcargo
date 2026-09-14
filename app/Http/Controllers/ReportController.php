<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Shipment;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Two concrete reports (shipments, billing) rather than a generic
 * report-builder — proportionate to what `reports.view`/`reports.export`
 * (in the permission catalog since Phase 1, unused until now) were
 * actually granted for. CSV export streams via plain fputcsv(); no export
 * library exists in this codebase and none is needed for a flat table.
 */
final class ReportController extends Controller
{
    public function shipments(Request $request): Response
    {
        $this->authorizeReports($request, 'reports.view');

        $filters = $this->dateRange($request);

        return Inertia::render('Reports/Shipments', [
            'filters' => $filters,
            'rows' => $this->shipmentsQuery($request, $filters)->limit(200)->get(),
            'canExport' => (bool) $request->user()?->hasPermission('reports.export'),
        ]);
    }

    public function exportShipments(Request $request): StreamedResponse
    {
        $this->authorizeReports($request, 'reports.export');

        $filters = $this->dateRange($request);
        $rows = $this->shipmentsQuery($request, $filters)->get();

        return $this->streamCsv('shipments-report.csv', ['Tracking #', 'Mode', 'Status', 'Branch', 'Customer', 'Booked at', 'Delivered at', 'Chargeable weight (kg)'], $rows, fn ($shipment) => [
            $shipment->tracking_number,
            $shipment->mode->value,
            $shipment->status,
            $shipment->branch?->name,
            $shipment->customer?->name,
            $shipment->booked_at?->toDateString(),
            $shipment->delivered_at?->toDateString(),
            $shipment->chargeable_weight_kg,
        ]);
    }

    public function billing(Request $request): Response
    {
        $this->authorizeReports($request, 'reports.view');

        $filters = $this->dateRange($request);
        $rows = $this->billingQuery($request, $filters)->limit(200)->get();

        return Inertia::render('Reports/Billing', [
            'filters' => $filters,
            'rows' => $rows,
            'totals' => [
                'total' => number_format((float) $this->billingQuery($request, $filters)->sum('total'), 2, '.', ''),
                'amountPaid' => number_format((float) $this->billingQuery($request, $filters)->sum('amount_paid'), 2, '.', ''),
                'balanceDue' => number_format((float) $this->billingQuery($request, $filters)->sum('balance_due'), 2, '.', ''),
            ],
            'canExport' => (bool) $request->user()?->hasPermission('reports.export'),
        ]);
    }

    public function exportBilling(Request $request): StreamedResponse
    {
        $this->authorizeReports($request, 'reports.export');

        $filters = $this->dateRange($request);
        $rows = $this->billingQuery($request, $filters)->get();

        return $this->streamCsv('billing-report.csv', ['Invoice #', 'Customer', 'Status', 'Currency', 'Total', 'Amount paid', 'Balance due', 'Issue date', 'Due date'], $rows, fn ($invoice) => [
            $invoice->invoice_number,
            $invoice->customer?->name,
            $invoice->status->value,
            $invoice->currency,
            $invoice->total,
            $invoice->amount_paid,
            $invoice->balance_due,
            $invoice->issue_date?->toDateString(),
            $invoice->due_date?->toDateString(),
        ]);
    }

    private function authorizeReports(Request $request, string $permission): void
    {
        abort_unless((bool) $request->user()?->hasPermission($permission), 403);
    }

    /** @return array{from: string, to: string} */
    private function dateRange(Request $request): array
    {
        return [
            'from' => $request->query('from') ?: now()->subDays(30)->toDateString(),
            'to' => $request->query('to') ?: now()->toDateString(),
        ];
    }

    /** @param array{from: string, to: string} $filters */
    private function shipmentsQuery(Request $request, array $filters): Builder
    {
        $user = $request->user();

        return Shipment::query()
            ->with(['branch:id,name', 'customer:id,name'])
            ->when($user?->customerProfile !== null, fn ($query) => $query->where('customer_id', $user->customerProfile->getKey()))
            ->when(
                $user?->customerProfile === null && $user?->branch_id !== null && ! $user->hasPermission('shipments.manage'),
                fn ($query) => $query->where('branch_id', $user->branch_id),
            )
            ->whereBetween('created_at', [Carbon::parse($filters['from'])->startOfDay(), Carbon::parse($filters['to'])->endOfDay()])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');
    }

    /** @param array{from: string, to: string} $filters */
    private function billingQuery(Request $request, array $filters): Builder
    {
        $user = $request->user();

        return Invoice::query()
            ->with('customer:id,name')
            ->when($user?->customerProfile !== null, fn ($query) => $query->where('customer_id', $user->customerProfile->getKey()))
            ->whereNotNull('issue_date')
            ->whereDate('issue_date', '>=', $filters['from'])
            ->whereDate('issue_date', '<=', $filters['to'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');
    }

    /** @param Collection<int, mixed> $rows */
    private function streamCsv(string $filename, array $header, Collection $rows, Closure $mapRow): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows, $mapRow): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $header);
            foreach ($rows as $row) {
                fputcsv($handle, $mapRow($row));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
