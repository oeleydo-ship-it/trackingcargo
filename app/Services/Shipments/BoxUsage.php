<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use Illuminate\Support\Facades\DB;

/**
 * Whether any package refers to given box sizes.
 *
 * Read straight from the table rather than through ShipmentPackage, so
 * packages on deleted shipments count too: a deleted shipment can be
 * restored, and its packages must still find the box they were booked with.
 */
final class BoxUsage
{
    /**
     * @param  iterable<int|string>  $sizeIds
     */
    public static function packagesUsing(iterable $sizeIds): int
    {
        $ids = collect($sizeIds)->map(fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return 0;
        }

        return DB::table('shipment_packages')->whereIn('box_size_id', $ids)->count();
    }
}
