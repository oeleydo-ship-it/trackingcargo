<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\Box;
use App\Models\BoxSize;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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

    public function setActive(BoxSize $size, bool $isActive, User $actor): BoxSize
    {
        return DB::transaction(function () use ($size, $isActive, $actor): BoxSize {
            $size->forceFill(['is_active' => $isActive])->save();

            $this->audit->record($isActive ? 'box-size.activated' : 'box-size.deactivated', $actor, $size);

            return $size;
        });
    }
}
