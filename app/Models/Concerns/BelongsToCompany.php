<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (self $model): void {
            $companyId = app(TenantContext::class)->requireCompanyId();

            if ($model->getAttribute('company_id') !== null && (int) $model->getAttribute('company_id') !== $companyId) {
                throw new \LogicException('The model company does not match the active tenant.');
            }

            $model->setAttribute('company_id', $companyId);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
