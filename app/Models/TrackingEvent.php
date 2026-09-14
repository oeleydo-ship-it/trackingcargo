<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['shipment_id', 'package_id', 'created_by', 'from_status', 'to_status', 'location', 'description', 'is_public', 'metadata', 'occurred_at'])]
final class TrackingEvent extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Tracking events are immutable.'));
        self::deleting(fn () => throw new LogicException('Tracking events are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ShipmentPackage::class, 'package_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
