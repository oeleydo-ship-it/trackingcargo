<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentMode;
use App\Enums\ShipmentStatusRole;
use App\Enums\ShipmentPartyRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'branch_id', 'batch_id', 'customer_id', 'tracking_number', 'mode', 'carrier_code', 'status',
    'origin_country_code', 'destination_country_code', 'destination_city',
    'declared_weight_kg', 'volumetric_weight_kg', 'chargeable_weight_kg', 'package_count',
    'currency', 'declared_value', 'cod_amount', 'last_location', 'last_status_at', 'booked_at', 'delivered_at',
])]
final class Shipment extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'mode' => ShipmentMode::class,
            'declared_weight_kg' => 'decimal:3',
            'volumetric_weight_kg' => 'decimal:3',
            'chargeable_weight_kg' => 'decimal:3',
            'declared_value' => 'decimal:2',
            'cod_amount' => 'decimal:2',
            'last_status_at' => 'datetime',
            'booked_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ShipmentBatch::class, 'batch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The status row behind the code stored on this shipment.
     *
     * Joined on the code rather than an id so that the value already written
     * to shipments.status — and echoed in webhooks, the public tracking page
     * and every report — stays exactly what it was when statuses became
     * per-company rows.
     */
    public function shipmentStatus(): BelongsTo
    {
        return $this->belongsTo(ShipmentStatus::class, 'status', 'code');
    }

    /**
     * Whether this shipment sits in the status carrying a given system role,
     * whatever the company has named it.
     */
    public function hasStatusRole(ShipmentStatusRole $role): bool
    {
        return $this->shipmentStatus?->role === $role;
    }

    public function parties(): HasMany
    {
        return $this->hasMany(ShipmentParty::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ShipmentPackage::class);
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class)->orderByDesc('occurred_at');
    }

    public function routeLegs(): HasMany
    {
        return $this->hasMany(RouteLeg::class)->orderBy('sequence');
    }

    public function customsClearances(): HasMany
    {
        return $this->hasMany(CustomsClearance::class)->orderByDesc('id');
    }

    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class)->orderByDesc('id');
    }

    public function consignor(): ?ShipmentParty
    {
        return $this->parties->first(fn (ShipmentParty $party): bool => $party->role === ShipmentPartyRole::Consignor);
    }

    public function consignee(): ?ShipmentParty
    {
        return $this->parties->first(fn (ShipmentParty $party): bool => $party->role === ShipmentPartyRole::Consignee);
    }
}
