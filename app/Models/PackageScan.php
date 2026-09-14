<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WarehouseScanType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'warehouse_id', 'shipment_package_id', 'scan_type', 'from_location_id', 'to_location_id',
    'load_unit_id', 'scanned_by', 'idempotency_key', 'metadata', 'occurred_at',
])]
final class PackageScan extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Package scans are immutable.'));
        self::deleting(fn () => throw new LogicException('Package scans are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'scan_type' => WarehouseScanType::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ShipmentPackage::class, 'shipment_package_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'to_location_id');
    }

    public function loadUnit(): BelongsTo
    {
        return $this->belongsTo(LoadUnit::class);
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
