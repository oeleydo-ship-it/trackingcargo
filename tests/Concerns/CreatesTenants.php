<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

trait CreatesTenants
{
    protected function createCompany(string $code): Company
    {
        return Company::query()->create([
            'code' => $code,
            'name' => $code.' Company',
            'slug' => strtolower($code).'-company',
            'country_code' => 'AE',
        ]);
    }

    protected function createBranch(Company $company, string $code): Branch
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return Branch::query()->create([
                'code' => $code,
                'name' => $code.' Branch',
                'tracking_prefix' => $code,
                'country_code' => 'AE',
                'city' => 'Dubai',
            ]);
        } finally {
            $context->forget();
        }
    }

    protected function createUser(Company $company, ?Branch $branch = null, UserStatus $status = UserStatus::Active): User
    {
        $user = new User;
        $user->forceFill([
            'company_id' => $company->getKey(),
            'branch_id' => $branch?->getKey(),
            'name' => 'Test User '.$company->code,
            'email' => strtolower($company->code).'-'.random_int(10000, 99999).'@example.test',
            'password' => Hash::make('secret-password'),
            'status' => $status,
            'email_verified_at' => now(),
        ]);
        $user->save();

        return $user;
    }

    protected function createCustomer(Company $company, string $name, array $overrides = []): Customer
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return Customer::query()->create([
                'customer_number' => $company->code.'-C-'.random_int(100000, 999999),
                'type' => 'individual',
                'name' => $name,
                ...$overrides,
            ]);
        } finally {
            $context->forget();
        }
    }

    /**
     * Creates a shipment with one 5kg package directly (no HTTP round trip),
     * for tests focused on freight/consolidation rather than shipment booking.
     */
    protected function createShipment(Company $company, Branch $branch, array $overrides = []): Shipment
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            $shipment = Shipment::query()->create([
                'branch_id' => $branch->getKey(),
                'tracking_number' => $company->code.'-'.$branch->code.'-'.random_int(10000000, 99999999),
                'mode' => 'air',
                'status' => 'received',
                'destination_country_code' => 'PH',
                'currency' => 'AED',
                ...$overrides,
            ]);

            $shipment->packages()->create([
                'package_number' => 1,
                'barcode' => $shipment->tracking_number.'-01',
                'weight_kg' => 5,
            ]);

            return $shipment;
        } finally {
            $context->forget();
        }
    }

    /** @param list<string> $permissions */
    protected function grantPermissions(User $user, array $permissions): void
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $user->company_id);

        try {
            foreach ($permissions as $slug) {
                Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'group' => 'test', 'platform_only' => false]);
            }

            $role = Role::query()->create(['name' => 'Test role '.random_int(1000, 9999), 'slug' => 'test-role-'.random_int(1000, 9999)]);
            $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id'));
            $user->roles()->attach($role->getKey(), ['company_id' => $user->company_id]);
        } finally {
            $context->forget();
        }
    }
}
