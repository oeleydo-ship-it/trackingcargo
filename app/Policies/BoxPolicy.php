<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Box;
use App\Models\User;

/**
 * Boxes (and their nested sizes) are a shipment-domain reference list, not
 * their own permission group — gated by the existing
 * shipments.view/shipments.manage permissions rather than adding a new
 * catalog entry for one settings screen, the same "no dedicated permission
 * for a sub-resource" precedent as ShipmentPackage/warehouse zones/locations.
 * BoxSize mutations authorize against the parent Box's `update` ability
 * (see StoreBoxSizeRequest), the same nested-resource shape
 * RateCardTier uses against RateCard.
 */
final class BoxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('shipments.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('shipments.manage');
    }

    public function update(User $user, Box $box): bool
    {
        return $user->company_id === $box->company_id && $user->hasPermission('shipments.manage');
    }
}
