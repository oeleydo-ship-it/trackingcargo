<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);
        $context->resolvePlatformBypass();

        try {
            foreach (config('permissions.catalog') as $slug => [$name, $group, $platformOnly]) {
                Permission::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group, 'platform_only' => $platformOnly]);
            }

            $platformRole = Role::query()->updateOrCreate(
                ['scope_key' => 'platform', 'slug' => 'super-admin'],
                ['company_id' => null, 'name' => 'Super Admin', 'is_system' => true],
            );
            $platformRole->permissions()->sync(Permission::query()->pluck('id'));
        } finally {
            $context->forget();
        }

        Company::query()->each(function (Company $company) use ($context): void {
            $context->resolveCompany((int) $company->getKey());
            try {
                foreach ($this->roleMap() as $slug => [$name, $permissions]) {
                    $role = Role::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'is_system' => true]);
                    $query = Permission::query()->where('platform_only', false);
                    if ($permissions !== ['*']) {
                        $query->whereIn('slug', $permissions);
                    }
                    $role->permissions()->sync($query->pluck('id'));
                }
            } finally {
                $context->forget();
            }
        });
    }

    private function roleMap(): array
    {
        return [
            'company-admin' => ['Company Admin', ['*']],
            'operations' => ['Operations', ['customers.view', 'customers.manage', 'shipments.view', 'shipments.manage', 'batches.view', 'batches.manage', 'tracking.view', 'tracking.update', 'warehouses.view', 'warehouses.manage', 'warehouse.receive', 'warehouse.dispatch', 'packages.scan', 'containers.view', 'containers.manage', 'flights.view', 'flights.manage', 'vessels.view', 'vessels.manage', 'manifests.view', 'manifests.manage', 'customs.view', 'customs.manage', 'deliveries.view', 'deliveries.manage', 'drivers.view', 'drivers.manage', 'vehicles.view', 'vehicles.manage', 'rates.view', 'rates.manage', 'billing.view', 'billing.manage', 'payments.view', 'payments.manage', 'notifications.view', 'notifications.manage', 'webhooks.view', 'webhooks.manage', 'reports.view', 'reports.export', 'audit-logs.view', 'statuses.view']],
            'warehouse' => ['Warehouse', ['shipments.view', 'batches.view', 'tracking.view', 'tracking.update', 'packages.scan', 'warehouses.view', 'warehouse.receive', 'warehouse.dispatch', 'containers.view', 'manifests.view']],
            'customer-service' => ['Customer Service', ['customers.view', 'customers.manage', 'shipments.view', 'batches.view', 'tracking.view', 'notifications.view', 'statuses.view']],
            'driver' => ['Driver', ['deliveries.view', 'deliveries.execute', 'tracking.view']],
            'customer' => ['Customer', ['shipments.view', 'tracking.view', 'billing.view', 'payments.view', 'notifications.view']],
        ];
    }
}
