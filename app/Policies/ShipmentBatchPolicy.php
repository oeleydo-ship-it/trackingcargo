<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ShipmentBatch;
use App\Models\User;

final class ShipmentBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('batches.view');
    }

    /** Branch scoping mirrors ShipmentPolicy::view() — a batch is branch-owned work. */
    public function view(User $user, ShipmentBatch $batch): bool
    {
        return $user->company_id === $batch->company_id
            && $user->customerProfile === null
            && ($user->branch_id === null || $user->branch_id === $batch->branch_id || $user->hasPermission('shipments.manage'))
            && $user->hasPermission('batches.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('batches.manage');
    }

    public function update(User $user, ShipmentBatch $batch): bool
    {
        return $user->company_id === $batch->company_id && $user->hasPermission('batches.manage');
    }

    /** Bulk status changes are gated on the same permission as a single transition. */
    public function transition(User $user, ShipmentBatch $batch): bool
    {
        return $user->company_id === $batch->company_id && $user->hasPermission('tracking.update');
    }

    public function delete(User $user, ShipmentBatch $batch): bool
    {
        return $this->update($user, $batch);
    }
}
