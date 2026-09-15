<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\Box;
use App\Models\BoxSize;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class BoxSizeService
{
    private const array FIELDS = ['name', 'length_cm', 'width_cm', 'height_cm'];

    public function __construct(private AuditService $audit) {}

    public function create(Box $box, array $data, User $actor): BoxSize
    {
        return DB::transaction(function () use ($box, $data, $actor): BoxSize {
            $size = $box->sizes()->create([
                ...Arr::only($data, self::FIELDS),
                'is_active' => true,
            ]);

            $this->audit->record('box-size.created', $actor, $size, newValues: $size->only(self::FIELDS));

            return $size;
        });
    }

    public function update(BoxSize $size, array $data, User $actor): BoxSize
    {
        return DB::transaction(function () use ($size, $data, $actor): BoxSize {
            $oldValues = $size->only(self::FIELDS);

            $size->fill(Arr::only($data, self::FIELDS))->save();

            $this->audit->record('box-size.updated', $actor, $size, oldValues: $oldValues, newValues: $size->only(self::FIELDS));

            return $size;
        });
    }

    /**
     * Deletes a size for good while no package has used it; see
     * BoxService::delete() for why a used size is deactivated instead.
     */
    public function delete(BoxSize $size, User $actor): void
    {
        if (BoxUsage::packagesUsing([$size->getKey()]) > 0) {
            throw ValidationException::withMessages([
                'size' => "The \"{$size->name}\" size has been used on shipments, so it can't be deleted. Deactivate it instead to stop it being offered.",
            ]);
        }

        DB::transaction(function () use ($size, $actor): void {
            $this->audit->record('box-size.deleted', $actor, $size, oldValues: $size->only(['box_id', 'name', 'length_cm', 'width_cm', 'height_cm']));

            $size->forceDelete();
        });
    }

    public function setActive(BoxSize $size, bool $isActive, User $actor): BoxSize
    {
        return DB::transaction(function () use ($size, $isActive, $actor): BoxSize {
            $size->forceFill(['is_active' => $isActive])->save();

            $this->audit->record($isActive ? 'box-size.activated' : 'box-size.deactivated', $actor, $size);

            return $size;
        });
    }
}
