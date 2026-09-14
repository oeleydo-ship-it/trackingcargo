<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddressType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['type', 'label', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code', 'latitude', 'longitude', 'contact_name', 'contact_phone', 'is_default'])]
final class Address extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'is_default' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }
}
