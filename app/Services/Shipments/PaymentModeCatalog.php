<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\Company;
use App\Tenancy\TenantContext;

final class PaymentModeCatalog
{
    private const DEFAULTS = [
        ['value' => 'cash', 'label' => 'Cash', 'active' => true],
        ['value' => 'bank_transfer', 'label' => 'Bank transfer', 'active' => true],
        ['value' => 'card', 'label' => 'Card', 'active' => true],
        ['value' => 'cod', 'label' => 'Cash on delivery', 'active' => true],
    ];

    public function company(): Company
    {
        return Company::query()->findOrFail(app(TenantContext::class)->requireCompanyId());
    }

    public function all(?Company $company = null): array
    {
        $company ??= $this->company();
        $settings = $company->settings ?? [];

        return array_key_exists('shipment_payment_modes', $settings)
            ? $settings['shipment_payment_modes']
            : self::DEFAULTS;
    }

    public function selectable(?Company $company = null, ?string $current = null): array
    {
        $modes = array_values(array_filter($this->all($company), fn (array $mode): bool => $mode['active'] || $mode['value'] === $current));

        if ($current !== null && $current !== '' && ! in_array($current, array_column($modes, 'value'), true)) {
            $modes[] = ['value' => $current, 'label' => $current, 'active' => false];
        }

        return $modes;
    }

    public function allows(string $value, ?Company $company = null, ?string $current = null): bool
    {
        return in_array($value, array_column($this->selectable($company, $current), 'value'), true);
    }
}
