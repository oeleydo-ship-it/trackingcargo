<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['shipment_id', 'box_size_id', 'load_unit_id', 'warehouse_location_id', 'package_number', 'barcode', 'description', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'volumetric_weight_kg', 'declared_value'])]
final class ShipmentPackage extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'volumetric_weight_kg' => 'decimal:3',
            'declared_value' => 'decimal:2',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function loadUnit(): BelongsTo
    {
        return $this->belongsTo(LoadUnit::class);
    }

    public function boxSize(): BelongsTo
    {
        return $this->belongsTo(BoxSize::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class, 'package_id')->orderByDesc('occurred_at');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(PackageScan::class, 'shipment_package_id')->orderByDesc('occurred_at');
    }
}
