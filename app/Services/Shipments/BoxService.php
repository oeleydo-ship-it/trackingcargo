<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\Box;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class BoxService
{
    public function __construct(private AuditService $audit) {}

    public function create(array $data, User $actor): Box
    {
        return DB::transaction(function () use ($data, $actor): Box {
            $box = Box::query()->create([
                'name' => $data['name'],
                'is_active' => true,
            ]);

            $this->audit->record('box.created', $actor, $box, newValues: ['name' => $box->name]);

            return $box;
        });
    }

    public function update(Box $box, array $data, User $actor): Box
    {
        return DB::transaction(function () use ($box, $data, $actor): Box {
            $oldName = $box->name;

            $box->fill(['name' => $data['name']])->save();

            $this->audit->record('box.updated', $actor, $box, oldValues: ['name' => $oldName], newValues: ['name' => $box->name]);

            return $box;
        });
    }

    /**
     * Deletes a box and its sizes for good — but only while no package has
     * ever used one of them.
     *
     * A package keeps a reference to the size it was booked with, and that is
     * how its shipment shows which box it went out in. Removing a size a
     * package points at would quietly turn those shipments' box into
     * "Custom", so a box in use is deactivated instead, which only hides it
     * from new bookings.
     *
     * The rows are removed outright rather than soft-deleted: nothing refers
     * to them, and a soft-deleted size would keep its name reserved (sizes
     * are unique per box, trashed rows included), so the same name could not
     * be added again.
     */
    public function delete(Box $box, User $actor): void
    {
        $sizeIds = $box->sizes()->withTrashed()->pluck('id');

        if (BoxUsage::packagesUsing($sizeIds) > 0) {
            throw ValidationException::withMessages([
                'box' => "\"{$box->name}\" has been used on shipments, so it can't be deleted. Deactivate it instead to stop it being offered.",
            ]);
        }

        DB::transaction(function () use ($box, $actor): void {
            $this->audit->record('box.deleted', $actor, $box, oldValues: [
                'name' => $box->name,
                'sizes' => $box->sizes()->withTrashed()->pluck('name')->all(),
            ]);

            $box->sizes()->withTrashed()->forceDelete();
            $box->forceDelete();
        });
    }

    public function setActive(Box $box, bool $isActive, User $actor): Box
    {
        return DB::transaction(function () use ($box, $isActive, $actor): Box {
            $box->forceFill(['is_active' => $isActive])->save();

            $this->audit->record($isActive ? 'box.activated' : 'box.deactivated', $actor, $box);

            return $box;
        });
    }
}
