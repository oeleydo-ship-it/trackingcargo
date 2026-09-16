<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentMode;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tracking-number format rule: a pattern used for a branch, a shipment
 * mode, or one exact combination of the two, overriding the company default.
 *
 * Each rule keeps its own running number — see NumberSequenceService and
 * TrackingNumberRules::resolve().
 */
#[Fillable(['branch_id', 'mode', 'format', 'sequence_padding'])]
final class TrackingNumberFormat extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'scope' => 'integer',
            'mode' => ShipmentMode::class,
            'sequence_padding' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // NOT NULL twins the uniqueness rule is built on; see the migration.
        self::saving(function (self $rule): void {
            $rule->scope = (int) ($rule->branch_id ?? 0);
            $rule->mode_key = $rule->mode?->value ?? '*';
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * How specific this rule is: branch and mode beats branch alone, which
     * beats mode alone, which beats the catch-all.
     */
    public function specificity(): int
    {
        return ($this->branch_id !== null ? 2 : 0) + ($this->mode !== null ? 1 : 0);
    }

    public function describe(): string
    {
        return implode(' · ', array_filter([
            $this->branch?->name ?? 'All branches',
            $this->mode?->value !== null ? ucfirst($this->mode->value) : 'All modes',
        ]));
    }
}
