<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentPartyRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['shipment_id', 'customer_id', 'role', 'name', 'company_name', 'email', 'phone', 'tax_id'])]
final class ShipmentParty extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['role' => ShipmentPartyRole::class];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * The customer record this party is, when the party is someone the company
     * already has on file. Null for a one-off name typed at the counter.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * A party is booked with at most one address — the pickup point for a
     * consignor, the delivery point for a consignee — so callers reading
     * "the address" do not have to pick one out of the collection.
     */
    public function address(): ?Address
    {
        return $this->addresses->first();
    }
}
