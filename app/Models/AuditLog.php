<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

#[Fillable(['company_id', 'branch_id', 'user_id', 'action', 'subject_type', 'subject_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'request_id', 'created_at'])]
final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::addGlobalScope(new CompanyScope);
        self::updating(fn () => throw new LogicException('Audit logs are immutable.'));
        self::deleting(fn () => throw new LogicException('Audit logs are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'new_values' => 'array',
            'old_values' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
