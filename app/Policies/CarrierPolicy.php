<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Carrier;
use App\Models\User;

/**
 * Carriers are shipment-domain reference data, gated by the existing
 * shipments permissions rather than a catalog entry of their own — the same
 * precedent as BoxPolicy. Whoever books shipments maintains the list of
 * carriers they book with.
 */
final class CarrierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('shipments.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('shipments.manage');
    }

    public function update(User $user, Carrier $carrier): bool
    {
        return $user->company_id === $carrier->company_id && $user->hasPermission('shipments.manage');
    }
}
