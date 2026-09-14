<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Customer;

/**
 * A live recompute from invoices' own `balance_due` (itself always a live
 * recompute from payments — see InvoiceService::recalculate()), never an
 * independently-maintained running counter. Same "recalculate from live
 * child rows" idiom as PackageLoadingService/WarehouseScanService.
 */
final readonly class CustomerBalanceService
{
    public function outstandingBalance(Customer $customer): float
    {
        return round((float) $customer->invoices()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->sum('balance_due'), 2);
    }
}
