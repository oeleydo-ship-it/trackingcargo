<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['driver_id', 'amount', 'currency', 'remitted_at', 'actor_id', 'idempotency_key', 'notes'])]
final class CodRemittance extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('COD remittances are immutable.'));
        self::deleting(fn () => throw new LogicException('COD remittances are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'remitted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
