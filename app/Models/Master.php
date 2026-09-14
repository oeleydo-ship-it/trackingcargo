<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MasterMode;
use App\Enums\MasterStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'branch_id', 'master_number', 'mode', 'status',
    'carrier_code', 'flight_number', 'origin_airport', 'destination_airport',
    'shipping_line', 'vessel_name', 'voyage_number', 'origin_port', 'destination_port',
    'scheduled_departure_at', 'scheduled_arrival_at', 'actual_departure_at', 'actual_arrival_at',
    'package_count', 'weight_kg',
])]
final class Master extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'mode' => MasterMode::class,
            'status' => MasterStatus::class,
            'weight_kg' => 'decimal:3',
            'scheduled_departure_at' => 'datetime',
            'scheduled_arrival_at' => 'datetime',
            'actual_departure_at' => 'datetime',
            'actual_arrival_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function routeLegs(): HasMany
    {
        return $this->hasMany(RouteLeg::class);
    }

    public function loadUnits(): HasMany
    {
        return $this->hasMany(LoadUnit::class);
    }

    public function manifests(): HasMany
    {
        return $this->hasMany(Manifest::class)->orderByDesc('version');
    }

    public function conveyanceLabel(): string
    {
        return match ($this->mode) {
            MasterMode::Air => trim("{$this->carrier_code} {$this->flight_number}"),
            MasterMode::Sea => trim("{$this->shipping_line} {$this->vessel_name} {$this->voyage_number}"),
        };
    }
}
