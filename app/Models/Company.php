<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Services\Shipments\ShipmentStatusProvisioner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'slug', 'legal_name', 'email', 'phone', 'country_code', 'timezone', 'default_currency', 'tracking_number_format', 'tracking_sequence_padding', 'allow_manual_tracking_number', 'default_tracking_mode', 'public_tracking_parties', 'batch_number_format', 'batch_sequence_padding', 'status', 'settings'])]
final class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'public_tracking_parties' => 'array',
            'status' => CompanyStatus::class,
            'allow_manual_tracking_number' => 'boolean',
            'tracking_sequence_padding' => 'integer',
            'batch_sequence_padding' => 'integer',
        ];
    }

    /**
     * A company is unusable until its shipment workflow exists, so the default
     * status set is written the moment the company is — every path that
     * creates one (seeder, tests, onboarding) gets it without having to
     * remember. ShipmentStatusProvisioner leaves an existing set alone.
     */
    protected static function booted(): void
    {
        static::created(function (self $company): void {
            app(ShipmentStatusProvisioner::class)->provision($company);
        });
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function shipmentStatuses(): HasMany
    {
        return $this->hasMany(ShipmentStatus::class)->orderBy('sequence');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
