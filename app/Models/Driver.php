<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['branch_id', 'zone_id', 'user_id', 'license_number', 'phone', 'status'])]
final class Driver extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'zone_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class)->orderByDesc('id');
    }

    public function location(): HasOne
    {
        return $this->hasOne(DriverLocation::class);
    }

    public function codRemittances(): HasMany
    {
        return $this->hasMany(CodRemittance::class)->orderByDesc('remitted_at');
    }
}
