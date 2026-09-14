<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WarehouseScanType;
use App\Models\User;
use App\Models\Warehouse;

final class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('warehouses.view');
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $user->company_id === $warehouse->company_id
            && ($user->branch_id === null || $warehouse->branch_id === null || $user->branch_id === $warehouse->branch_id || $user->hasPermission('warehouses.manage'))
            && $user->hasPermission('warehouses.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('warehouses.manage');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->company_id === $warehouse->company_id && $user->hasPermission('warehouses.manage');
    }

    /**
     * A not-yet-created scan has no model to authorize against, so the scan
     * type is passed as a second policy argument, the same shape as
     * MasterPolicy::create()'s mode argument — receiving and dispatching are
     * gated by their own dedicated permissions; sorting and container
     * load/unload confirmation only need the base scanning permission.
     * Branch-scoped the same way view() is: a branch-scoped user may only
     * scan at their own branch's warehouses unless they hold
     * warehouses.manage — without this a branch-scoped packages.scan user
     * could receive/dispatch/sort packages at a warehouse belonging to a
     * different branch, despite being unable to even view that warehouse.
     */
    public function scan(User $user, Warehouse $warehouse, ?string $scanType = null): bool
    {
        if ($user->company_id !== $warehouse->company_id || ! $user->hasPermission('packages.scan')) {
            return false;
        }

        if ($user->branch_id !== null && $warehouse->branch_id !== null && $user->branch_id !== $warehouse->branch_id && ! $user->hasPermission('warehouses.manage')) {
            return false;
        }

        return match ($scanType) {
            WarehouseScanType::Receive->value => $user->hasPermission('warehouse.receive'),
            WarehouseScanType::Dispatch->value => $user->hasPermission('warehouse.dispatch'),
            default => true,
        };
    }
}
