<?php

declare(strict_types=1);

namespace App\Tenancy;

use LogicException;

final class TenantContext
{
    private bool $resolved = false;

    private ?int $companyId = null;

    public function resolveCompany(int $companyId): void
    {
        if ($companyId < 1) {
            throw new LogicException('A valid company identifier is required.');
        }

        $this->resolved = true;
        $this->companyId = $companyId;
    }

    public function resolvePlatformBypass(): void
    {
        $this->resolved = true;
        $this->companyId = null;
    }

    public function forget(): void
    {
        $this->resolved = false;
        $this->companyId = null;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function isPlatformBypass(): bool
    {
        return $this->resolved && $this->companyId === null;
    }

    public function companyId(): ?int
    {
        if (! $this->resolved) {
            throw new LogicException('Tenant context has not been resolved.');
        }

        return $this->companyId;
    }

    public function requireCompanyId(): int
    {
        return $this->companyId()
            ?? throw new LogicException('This operation requires a company tenant.');
    }
}
