<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryAssignmentStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'branch_id', 'shipment_id', 'driver_id', 'vehicle_id', 'zone_id', 'status',
    'assigned_by', 'assigned_at', 'scheduled_date', 'delivered_at', 'notes',
])]
final class DeliveryAssignment extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => DeliveryAssignmentStatus::class,
            'assigned_at' => 'datetime',
            'scheduled_date' => 'date',
            'delivered_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'zone_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class)->orderByDesc('attempted_at');
    }
}
