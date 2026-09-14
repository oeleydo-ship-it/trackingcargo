<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoadUnitStatus;
use App\Enums\LoadUnitType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['master_id', 'type', 'unit_number', 'seal_number', 'status', 'package_count', 'weight_kg'])]
final class LoadUnit extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => LoadUnitType::class,
            'status' => LoadUnitStatus::class,
            'weight_kg' => 'decimal:3',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(Master::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ShipmentPackage::class);
    }
}
