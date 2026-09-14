<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Documentable;
use App\Enums\CustomsClearanceStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['branch_id', 'shipment_id', 'status', 'declaration_number', 'customs_office', 'submitted_by', 'submitted_at', 'cleared_at', 'notes'])]
final class CustomsClearance extends Model implements Documentable
{
    use BelongsToCompany, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => CustomsClearanceStatus::class,
            'submitted_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomsClearanceEvent::class)->orderByDesc('occurred_at');
    }

    public function duties(): HasMany
    {
        return $this->hasMany(CustomsDuty::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(CustomsInspection::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function documentStoragePath(): string
    {
        return "documents/{$this->company_id}/customs-clearances/{$this->getKey()}";
    }
}
