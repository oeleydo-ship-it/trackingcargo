<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomsClearanceStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['customs_clearance_id', 'from_status', 'to_status', 'reason', 'actor_id', 'idempotency_key', 'metadata', 'occurred_at'])]
final class CustomsClearanceEvent extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Customs clearance events are immutable.'));
        self::deleting(fn () => throw new LogicException('Customs clearance events are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'from_status' => CustomsClearanceStatus::class,
            'to_status' => CustomsClearanceStatus::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function clearance(): BelongsTo
    {
        return $this->belongsTo(CustomsClearance::class, 'customs_clearance_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
