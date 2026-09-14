<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\Box;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

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

    public function setActive(Box $box, bool $isActive, User $actor): Box
    {
        return DB::transaction(function () use ($box, $isActive, $actor): Box {
            $box->forceFill(['is_active' => $isActive])->save();

            $this->audit->record($isActive ? 'box.activated' : 'box.deactivated', $actor, $box);

            return $box;
        });
    }
}
