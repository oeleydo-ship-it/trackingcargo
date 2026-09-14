<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RouteLegStatus;
use App\Enums\ShipmentMode;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shipment_id', 'master_id', 'sequence', 'mode', 'origin_location', 'destination_location', 'status', 'scheduled_departure_at', 'scheduled_arrival_at', 'actual_departure_at', 'actual_arrival_at'])]
final class RouteLeg extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'mode' => ShipmentMode::class,
            'status' => RouteLegStatus::class,
            'scheduled_departure_at' => 'datetime',
            'scheduled_arrival_at' => 'datetime',
            'actual_departure_at' => 'datetime',
            'actual_arrival_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(Master::class);
    }
}
