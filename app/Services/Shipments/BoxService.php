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
    private const string STANDARD_BOX_NAME = 'Package';

    /**
     * name => [length, width, height] in cm; null dimensions mean the size is
     * measured on each package.
     *
     * @var array<string, array{?float, ?float, ?float}>
     */
    private const array STANDARD_SIZES = [
        'Mega Jumbo' => [61, 61, 75],
        'Jumbo' => [61, 61, 66],
        'Large' => [57, 57, 57],
        'Medium' => [46, 46, 72],
        'Small' => [46, 46, 30],
        'Extra Small' => [36, 36, 30],
        'Odd Size' => [null, null, null],
        'Drum' => [58, 58, 94],
        'Crate' => [null, null, null],
    ];

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

    /**
     * The package sizes most cargo workspaces need, added in one go.
     *
     * Odd Size and Crate carry no dimensions: those are measured on each
     * package. Drum is a cylinder, stored as the box that contains it
     * (diameter × diameter × height) so it volumetrically weighs the same as
     * the space it actually takes up on a pallet.
     *
     * @return array{box: Box, added: int}
     */
    public function installStandardSizes(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $box = Box::query()->firstOrCreate(['name' => self::STANDARD_BOX_NAME], ['is_active' => true]);

            if ($box->wasRecentlyCreated) {
                $this->audit->record('box.created', $actor, $box, newValues: $box->only(['name', 'is_active']));
            }

            $added = 0;

            foreach (self::STANDARD_SIZES as $name => [$length, $width, $height]) {
                $existing = $box->sizes()->withTrashed()->where('name', $name)->exists();

                if ($existing) {
                    continue;
                }

                $box->sizes()->create([
                    'name' => $name,
                    'is_custom' => $length === null,
                    'length_cm' => $length,
                    'width_cm' => $width,
                    'height_cm' => $height,
                    'is_active' => true,
                ]);

                $added++;
            }

            $this->audit->record('box.standard-sizes-installed', $actor, $box, newValues: ['added' => $added]);

            return ['box' => $box, 'added' => $added];
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
