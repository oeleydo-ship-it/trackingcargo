<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BatchStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Support\SearchWords;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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

    /**
     * Free-text search across what someone is likely to remember about a
     * batch: its number, reference or notes, who opened it, or the tracking
     * number of a shipment inside it. Every word has to match something.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        foreach (SearchWords::split($term) as $word) {
            $like = SearchWords::like($word);

            $query->where(function (Builder $query) use ($like): void {
                $query->where('batch_number', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhereHas('creator', fn (Builder $creator) => $creator->where('name', 'like', $like))
                    ->orWhereHas('shipments', fn (Builder $shipment) => $shipment->where('tracking_number', 'like', $like));
            });
        }

        return $query;
    }

    /**
     * The batches list's filters; blank values are ignored. `from`/`to` bound
     * the day the batch was opened, both ends inclusive.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilteredBy(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->search((string) $filters['q']))
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(filled($filters['branch_id'] ?? null), fn (Builder $query) => $query->where('branch_id', $filters['branch_id']))
            ->when(filled($filters['from'] ?? null), fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay()))
            ->when(filled($filters['to'] ?? null), fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay()));
    }
}
