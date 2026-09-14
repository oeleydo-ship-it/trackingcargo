<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'is_system'])]
final class Role extends Model
{
    protected static function booted(): void
    {
        self::addGlobalScope(new CompanyScope);

        self::creating(function (self $role): void {
            $contextCompanyId = app(TenantContext::class)->companyId();

            if ($contextCompanyId !== null) {
                if ($role->company_id !== null && (int) $role->company_id !== $contextCompanyId) {
                    throw new \LogicException('The role company does not match the active tenant.');
                }

                $role->company_id = $contextCompanyId;
            }

            $role->scope_key = $role->company_id === null ? 'platform' : 'company:'.$role->company_id;
        });
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['company_id', 'assigned_by'])
            ->withTimestamps();
    }
}
