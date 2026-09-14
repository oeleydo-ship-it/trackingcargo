<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentStatusRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A status a company's shipments can be in.
 *
 * Statuses used to be a fixed PHP enum. They are now per-company rows so a
 * company can name, colour, order and extend its own workflow — see
 * ShipmentStatusRole for the small set of behaviours the system still needs
 * to be able to find underneath whatever a company calls them.
 */
#[Fillable(['code', 'name', 'color', 'role', 'sequence', 'is_public', 'is_terminal', 'is_initial', 'is_active'])]
final class ShipmentStatus extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected function casts(): array
    {
        return [
            'role' => ShipmentStatusRole::class,
            'sequence' => 'integer',
            'is_public' => 'boolean',
            'is_terminal' => 'boolean',
            'is_initial' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Shipments currently in this status, matched on the stored code. */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'status', 'code');
    }

    /** The statuses a shipment in this one may move to. */
    public function transitionsTo(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'shipment_status_transitions', 'from_status_id', 'to_status_id')
            ->orderBy('sequence')
            ->orderBy('name');
    }

    /** The statuses that may move to this one. */
    public function transitionsFrom(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'shipment_status_transitions', 'to_status_id', 'from_status_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sequence')->orderBy('name');
    }

    public function hasRole(ShipmentStatusRole $role): bool
    {
        return $this->role === $role;
    }
}
