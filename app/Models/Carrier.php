<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company the cargo is handed to for moving: an airline, shipping line,
 * courier or trucking firm. Maintained per company under Settings → Carriers
 * and offered on the booking form.
 *
 * Not a shipment party — the consignor and consignee are the cargo's owners;
 * the carrier only moves it.
 */
#[Fillable(['name', 'code', 'modes', 'integration_code', 'website', 'contact_name', 'contact_email', 'contact_phone', 'is_active'])]
final class Carrier extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected function casts(): array
    {
        return [
            'modes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
