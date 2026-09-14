<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DriverStatus;
use App\Enums\UserStatus;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Enums\WarehouseZoneType;
use App\Models\Box;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('setup.seed_demo_data')) {
            $this->call(AuthorizationSeeder::class);

            $this->command?->warn('Production: seeded permissions and roles only. Sample companies and their known-password accounts were skipped (set SEED_DEMO_DATA=true to include them).');
            $this->command?->info('Open the site to create the first superadmin at /setup.');

            return;
        }

        $password = Hash::make((string) env('SEED_DEFAULT_PASSWORD', 'ChangeMe123!'));
        $context = app(TenantContext::class);

        $companies = collect([
            ['code' => 'GLX', 'name' => 'GulfLink Express', 'slug' => 'gulflink-express', 'country_code' => 'AE', 'timezone' => 'Asia/Dubai', 'default_currency' => 'AED'],
            ['code' => 'PAC', 'name' => 'Pacific Cargo Network', 'slug' => 'pacific-cargo-network', 'country_code' => 'PH', 'timezone' => 'Asia/Manila', 'default_currency' => 'PHP'],
        ])->map(fn (array $data): Company => Company::query()->updateOrCreate(['code' => $data['code']], $data));

        foreach ($companies as $company) {
            $context->resolveCompany((int) $company->getKey());
            try {
                $branch = Branch::query()->updateOrCreate(
                    ['code' => $company->code === 'GLX' ? 'DXB' : 'MNL'],
                    [
                        'name' => $company->code === 'GLX' ? 'Dubai Head Office' : 'Manila Head Office',
                        'tracking_prefix' => $company->code === 'GLX' ? 'DXB' : 'MNL',
                        'country_code' => $company->country_code,
                        'city' => $company->code === 'GLX' ? 'Dubai' : 'Manila',
                        'timezone' => $company->timezone,
                        'is_head_office' => true,
                    ],
                );

                $companyAdmin = User::query()->firstOrNew(['email' => strtolower($company->code).'@cargoflow.test']);
                $companyAdmin->forceFill([
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch->getKey(),
                    'name' => $company->name.' Administrator',
                    'password' => $password,
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                ])->save();

                $warehouse = Warehouse::query()->updateOrCreate(
                    ['code' => $branch->code.'-WH1'],
                    [
                        'branch_id' => $branch->getKey(),
                        'name' => $branch->name.' Warehouse',
                        'city' => $branch->city,
                        'country_code' => $branch->country_code,
                        'is_active' => true,
                    ],
                );

                $receiving = $warehouse->zones()->updateOrCreate(
                    ['code' => 'RCV'],
                    ['name' => 'Receiving', 'type' => WarehouseZoneType::Receiving, 'is_active' => true],
                );
                $receiving->locations()->updateOrCreate(
                    ['code' => 'RCV-01'],
                    ['warehouse_id' => $warehouse->getKey(), 'is_active' => true],
                );

                $storage = $warehouse->zones()->updateOrCreate(
                    ['code' => 'STO'],
                    ['name' => 'Storage', 'type' => WarehouseZoneType::Storage, 'is_active' => true],
                );
                $storage->locations()->updateOrCreate(
                    ['code' => 'STO-A1'],
                    ['warehouse_id' => $warehouse->getKey(), 'is_active' => true],
                );

                foreach ($this->defaultBoxes() as $boxName => $sizes) {
                    $box = Box::query()->updateOrCreate(['name' => $boxName], ['is_active' => true]);

                    foreach ($sizes as $sizeData) {
                        $box->sizes()->updateOrCreate(
                            ['name' => $sizeData['name']],
                            [...$sizeData, 'is_active' => true],
                        );
                    }
                }
            } finally {
                $context->forget();
            }
        }

        $this->call(AuthorizationSeeder::class);

        $context->resolvePlatformBypass();
        try {
            $superAdmin = User::query()->firstOrNew(['email' => 'admin@cargoflow.test']);
            $superAdmin->forceFill([
                'name' => 'CargoFlow Super Admin',
                'password' => $password,
                'status' => UserStatus::Active,
                'is_platform_admin' => true,
                'email_verified_at' => now(),
            ])->save();
            $superAdmin->roles()->syncWithoutDetaching([
                Role::query()->where('slug', 'super-admin')->valueOrFail('id') => ['company_id' => null],
            ]);
        } finally {
            $context->forget();
        }

        foreach ($companies as $company) {
            $context->resolveCompany((int) $company->getKey());
            try {
                $admin = User::query()->where('company_id', $company->getKey())->firstOrFail();
                $role = Role::query()->where('slug', 'company-admin')->firstOrFail();
                $admin->roles()->syncWithoutDetaching([
                    $role->getKey() => ['company_id' => $company->getKey(), 'assigned_by' => null],
                ]);

                $branch = Branch::query()->where('company_id', $company->getKey())->firstOrFail();

                $zone = DeliveryZone::query()->updateOrCreate(
                    ['code' => 'Z1'],
                    ['branch_id' => $branch->getKey(), 'name' => $branch->city.' Central'],
                );

                Vehicle::query()->updateOrCreate(
                    ['registration_number' => $company->code.'-V1'],
                    ['branch_id' => $branch->getKey(), 'type' => VehicleType::Van, 'capacity_kg' => 500, 'status' => VehicleStatus::Active],
                );

                $driverUser = User::query()->firstOrNew(['email' => strtolower($company->code).'-driver@cargoflow.test']);
                $driverUser->forceFill([
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch->getKey(),
                    'name' => $branch->name.' Driver',
                    'password' => $password,
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                ])->save();

                $driverRole = Role::query()->where('slug', 'driver')->firstOrFail();
                $driverUser->roles()->syncWithoutDetaching([
                    $driverRole->getKey() => ['company_id' => $company->getKey(), 'assigned_by' => null],
                ]);

                Driver::query()->updateOrCreate(
                    ['user_id' => $driverUser->getKey()],
                    ['branch_id' => $branch->getKey(), 'zone_id' => $zone->getKey(), 'status' => DriverStatus::Active],
                );
            } finally {
                $context->forget();
            }
        }

        $this->call(DemoDataSeeder::class);
    }

    /** @return array<string, list<array{name: string, length_cm: float, width_cm: float, height_cm: float}>> */
    private function defaultBoxes(): array
    {
        return [
            'Standard Box' => [
                ['name' => 'Small', 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10],
                ['name' => 'Medium', 'length_cm' => 35, 'width_cm' => 25, 'height_cm' => 20],
                ['name' => 'Large', 'length_cm' => 50, 'width_cm' => 40, 'height_cm' => 30],
                ['name' => 'Jumbo', 'length_cm' => 80, 'width_cm' => 60, 'height_cm' => 50],
            ],
            'Poly Mailer' => [
                ['name' => 'Small', 'length_cm' => 25, 'width_cm' => 18, 'height_cm' => 2],
                ['name' => 'Medium', 'length_cm' => 35, 'width_cm' => 27, 'height_cm' => 3],
            ],
        ];
    }
}
