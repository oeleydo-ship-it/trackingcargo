<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentMode;
use App\Enums\ShipmentPartyRole;
use App\Enums\ShipmentStatusRole;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Support\SearchWords;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

#[Fillable([
    'branch_id', 'batch_id', 'customer_id', 'tracking_number', 'mode', 'carrier_id', 'carrier_code', 'status',
    'origin_country_code', 'destination_country_code', 'destination_city',
    'declared_weight_kg', 'volumetric_weight_kg', 'chargeable_weight_kg', 'package_count',
    'currency', 'declared_value', 'cod_amount', 'payment_mode', 'last_location', 'last_status_at', 'booked_at', 'delivered_at',
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

    /**
     * Free-text search across everything a clerk might be holding when they
     * look a shipment up: the tracking number, either party, the customer,
     * the destination city, the batch, the carrier or a package barcode.
     *
     * The term is split into words and every word has to match something, so
     * "acme manila" finds Acme's shipments to Manila without the two words
     * having to sit in the same column.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        foreach (SearchWords::split($term) as $word) {
            $like = SearchWords::like($word);

            $query->where(function (Builder $query) use ($word, $like): void {
                $query->where('tracking_number', 'like', $like)
                    ->orWhere('destination_city', 'like', $like)
                    ->orWhereHas('parties', fn (Builder $party) => $party->where(fn (Builder $party) => $party
                        ->where('name', 'like', $like)
                        ->orWhere('company_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)))
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->search($word))
                    ->orWhereHas('packages', fn (Builder $package) => $package->where('barcode', 'like', $like))
                    ->orWhereHas('batch', fn (Builder $batch) => $batch->where(fn (Builder $batch) => $batch
                        ->where('batch_number', 'like', $like)
                        ->orWhere('reference', 'like', $like)))
                    ->orWhereHas('carrier', fn (Builder $carrier) => $carrier->where('name', 'like', $like));
            });
        }

        return $query;
    }

    /**
     * The shipments list's filters. Blank values are ignored, so a partly
     * filled form narrows only by what was actually chosen. `from`/`to` bound
     * the day the shipment was booked into the system, both ends inclusive.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilteredBy(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->search((string) $filters['q']))
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(filled($filters['mode'] ?? null), fn (Builder $query) => $query->where('mode', $filters['mode']))
            ->when(filled($filters['branch_id'] ?? null), fn (Builder $query) => $query->where('branch_id', $filters['branch_id']))
            ->when(filled($filters['carrier_id'] ?? null), fn (Builder $query) => $query->where('carrier_id', $filters['carrier_id']))
            ->when(filled($filters['country'] ?? null), fn (Builder $query) => $query->where('destination_country_code', $filters['country']))
            ->when(filled($filters['from'] ?? null), fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay()))
            ->when(filled($filters['to'] ?? null), fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay()));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ShipmentBatch::class, 'batch_id');
    }

    /** The carrier moving this shipment, from the company's own list. */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The status row behind the code stored on this shipment.
     *
     * Not an Eloquent relation: the same code can exist in the company default
     * workflow and in a customised branch's copy, and which one applies
     * depends on this shipment's branch — something a join on the code alone
     * cannot express. ShipmentStatusRepository resolves it; the result is kept
     * as the `shipmentStatus` relation so it serialises as `shipment_status`
     * for the pages, exactly as before.
     */
    public function resolvedStatus(): ?ShipmentStatus
    {
        if (! $this->relationLoaded('shipmentStatus')) {
            $this->setRelation('shipmentStatus', app(ShipmentStatusRepository::class)->statusOf($this));
        }

        return $this->getRelation('shipmentStatus');
    }

    /**
     * Whether this shipment sits in the status carrying a given system role,
     * whatever the company or branch has named it.
     */
    public function hasStatusRole(ShipmentStatusRole $role): bool
    {
        return $this->resolvedStatus()?->role === $role;
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
