<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CustomsClearanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Models\CustomsClearance;
use App\Models\DeliveryAssignment;
use App\Models\Invoice;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One dashboard, differentiated by role rather than N separate page
 * components — every widget is independently gated by the same
 * permission/identity checks the rest of the app already uses
 * (hasPermission(), customerProfile, driver), so a user only ever sees
 * counts for data they could otherwise navigate to and view themselves.
 * A customer portal login's widgets are scoped to their own customer_id,
 * matching the same restriction ShipmentPolicy/InvoicePolicy enforce
 * per-record (see the Phase 10 security review).
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'shipments' => $user !== null ? $this->shipmentWidget($user) : null,
            'deliveries' => $user?->driver !== null ? $this->driverWidget($user) : ($user?->hasPermission('deliveries.view') ? $this->deliveriesWidget() : null),
            'customs' => $user?->hasPermission('customs.view') ? $this->customsWidget() : null,
            'billing' => $user?->hasPermission('billing.view') ? $this->billingWidget($user) : null,
            'webhooks' => $user?->hasPermission('webhooks.view') ? $this->webhooksWidget() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function shipmentWidget(User $user): array
    {
        $query = Shipment::query();

        if ($user->customerProfile !== null) {
            $query->where('customer_id', $user->customerProfile->getKey());
        } elseif ($user->branch_id !== null && ! $user->hasPermission('shipments.manage')) {
            $query->where('branch_id', $user->branch_id);
        }

        if (! $user->hasPermission('shipments.view')) {
            return ['visible' => false];
        }

        $counts = (clone $query)
            ->selectRaw("count(*) as total, sum(status = 'in_transit') as in_transit, sum(status = 'out_for_delivery') as out_for_delivery, sum(status = 'delivered' and date(delivered_at) = ?) as delivered_today, sum(status = 'exception') as exceptions, sum(date(created_at) = ?) as booked_today", [now()->toDateString(), now()->toDateString()])
            ->first();

        return [
            'visible' => true,
            'bookedToday' => (int) $counts->booked_today,
            'inTransit' => (int) $counts->in_transit,
            'outForDelivery' => (int) $counts->out_for_delivery,
            'deliveredToday' => (int) $counts->delivered_today,
            'exceptions' => (int) $counts->exceptions,
        ];
    }

    /** @return array<string, mixed> */
    private function driverWidget(User $user): array
    {
        $driver = $user->driver;

        $counts = DeliveryAssignment::query()
            ->where('driver_id', $driver->getKey())
            ->selectRaw("sum(status = 'out_for_delivery') as active, sum(status = 'delivered' and date(delivered_at) = ?) as delivered_today", [now()->toDateString()])
            ->first();

        return [
            'visible' => true,
            'role' => 'driver',
            'active' => (int) $counts->active,
            'deliveredToday' => (int) $counts->delivered_today,
        ];
    }

    /** @return array<string, mixed> */
    private function deliveriesWidget(): array
    {
        $counts = DeliveryAssignment::query()
            ->selectRaw("sum(status = 'out_for_delivery') as out_for_delivery, sum(status = 'delivered' and date(delivered_at) = ?) as delivered_today", [now()->toDateString()])
            ->first();

        return [
            'visible' => true,
            'role' => 'ops',
            'outForDelivery' => (int) $counts->out_for_delivery,
            'deliveredToday' => (int) $counts->delivered_today,
        ];
    }

    /** @return array<string, mixed> */
    private function customsWidget(): array
    {
        return [
            'visible' => true,
            'pending' => CustomsClearance::query()->whereIn('status', [CustomsClearanceStatus::Pending->value, CustomsClearanceStatus::UnderReview->value])->count(),
            'held' => CustomsClearance::query()->where('status', CustomsClearanceStatus::Held->value)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function billingWidget(User $user): array
    {
        $query = Invoice::query()->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value]);

        if ($user->customerProfile !== null) {
            $query->where('customer_id', $user->customerProfile->getKey());
        }

        return [
            'visible' => true,
            'outstandingBalance' => number_format((float) $query->sum('balance_due'), 2, '.', ''),
            'openInvoices' => (clone $query)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function webhooksWidget(): array
    {
        return [
            'visible' => true,
            'failedLast7Days' => WebhookDelivery::query()
                ->where('status', WebhookDeliveryStatus::Failed->value)
                ->where('updated_at', '>=', now()->subDays(7))
                ->count(),
        ];
    }
}
