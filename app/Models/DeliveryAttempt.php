<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Documentable;
use App\Enums\DeliveryAttemptOutcome;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

#[Fillable([
    'delivery_assignment_id', 'outcome', 'attempted_at', 'recipient_name',
    'failure_reason', 'reschedule_date', 'notes', 'collected_amount', 'collected_currency',
    'actor_id', 'idempotency_key',
])]
final class DeliveryAttempt extends Model implements Documentable
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Delivery attempts are immutable.'));
        self::deleting(fn () => throw new LogicException('Delivery attempts are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'outcome' => DeliveryAttemptOutcome::class,
            'attempted_at' => 'immutable_datetime',
            'reschedule_date' => 'date',
            'collected_amount' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DeliveryAssignment::class, 'delivery_assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function documentStoragePath(): string
    {
        return "documents/{$this->company_id}/delivery-attempts/{$this->getKey()}";
    }
}
