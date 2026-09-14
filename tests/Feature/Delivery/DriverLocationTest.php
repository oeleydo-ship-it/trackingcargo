<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use App\Models\DriverLocation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class DriverLocationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_driver_can_ping_their_own_location_and_it_upserts(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('DLA', ['drivers.view', 'drivers.manage']);
        $driver = $this->createDriver($company, $branch, $actor);

        $this->actingAs($driver->user)
            ->postJson('/driver-locations', ['latitude' => 25.2, 'longitude' => 55.3])
            ->assertOk();

        $this->actingAs($driver->user)
            ->postJson('/driver-locations', ['latitude' => 25.21, 'longitude' => 55.31])
            ->assertOk();

        $this->withTenant($company, function () use ($driver): void {
            self::assertSame(1, DriverLocation::query()->where('driver_id', $driver->getKey())->count());
            $location = DriverLocation::query()->where('driver_id', $driver->getKey())->firstOrFail();
            self::assertEqualsWithDelta(25.21, (float) $location->latitude, 0.001);
        });
    }

    public function test_a_user_without_a_driver_profile_cannot_ping_a_location(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('DLB', []);
        $this->grantPermissions($actor, ['deliveries.execute']);

        $this->actingAs($actor)
            ->postJson('/driver-locations', ['latitude' => 25.2, 'longitude' => 55.3])
            ->assertForbidden();
    }

    public function test_location_pings_are_rate_limited(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('DLC', ['drivers.view', 'drivers.manage']);
        $driver = $this->createDriver($company, $branch, $actor);

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($driver->user)
                ->postJson('/driver-locations', ['latitude' => 25.2, 'longitude' => 55.3])
                ->assertOk();
        }

        $this->actingAs($driver->user)
            ->postJson('/driver-locations', ['latitude' => 25.2, 'longitude' => 55.3])
            ->assertStatus(429);
    }

    /** @return array{0: Company, 1: Branch, 2: User} */
    private function setUpTenant(string $code, array $permissions): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, $permissions);

        return [$company, $branch, $actor];
    }

    private function createDriver(Company $company, Branch $branch, User $actor): Driver
    {
        $driverUser = $this->createUser($company, $branch);
        $this->grantPermissions($driverUser, ['deliveries.view', 'deliveries.execute']);
        $this->actingAs($actor)->post('/drivers', ['user_id' => $driverUser->getKey(), 'branch_id' => $branch->getKey()]);

        return $this->withTenantReturn($company, fn () => Driver::query()->where('user_id', $driverUser->getKey())->with('user')->firstOrFail());
    }

    private function withTenant(Company $company, \Closure $callback): void
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $callback();
        } finally {
            $context->forget();
        }
    }

    private function withTenantReturn(Company $company, \Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
