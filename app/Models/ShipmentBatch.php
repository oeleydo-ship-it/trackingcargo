<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BatchStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['branch_id', 'batch_number', 'reference', 'status', 'notes', 'created_by'])]
final class ShipmentBatch extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['status' => BatchStatus::class];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Named `creator`, not `createdBy`: Eloquent serializes a relation under
     * the snake_case of its name, so `createdBy` would emit `created_by` and
     * overwrite the FK column of the same name in the JSON handed to Inertia.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'batch_id');
    }
}
