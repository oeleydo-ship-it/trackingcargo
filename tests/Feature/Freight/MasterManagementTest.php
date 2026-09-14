<?php

declare(strict_types=1);

namespace Tests\Feature\Freight;

use App\Enums\MasterStatus;
use App\Models\Company;
use App\Models\Master;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class MasterManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function airPayload(int $branchId): array
    {
        return [
            'branch_id' => $branchId,
            'mode' => 'air',
            'carrier_code' => 'EK',
            'flight_number' => 'EK332',
            'origin_airport' => 'DXB',
            'destination_airport' => 'MNL',
        ];
    }

    public function test_a_user_with_flights_permission_can_create_an_air_master(): void
    {
        $company = $this->createCompany('MMA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['flights.view', 'flights.manage']);

        $this->actingAs($actor)
            ->post('/freight/masters', $this->airPayload($branch->getKey()))
            ->assertRedirect();

        $master = $this->findMaster($company);
        self::assertSame("{$company->code}-DXB-M000001", $master->master_number);
        self::assertSame(MasterStatus::Open, $master->status);
    }

    public function test_sea_fields_are_prohibited_on_an_air_master(): void
    {
        $company = $this->createCompany('MMB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['flights.view', 'flights.manage']);

        $payload = $this->airPayload($branch->getKey());
        $payload['vessel_name'] = 'Should not be allowed';

        $this->actingAs($actor)
            ->post('/freight/masters', $payload)
            ->assertSessionHasErrors('vessel_name');
    }

    public function test_a_user_with_only_vessels_permission_cannot_manage_an_air_master(): void
    {
        $company = $this->createCompany('MMC');
        $branch = $this->createBranch($company, 'DXB');
        $seaActor = $this->createUser($company, $branch);
        $this->grantPermissions($seaActor, ['vessels.view', 'vessels.manage']);

        $this->actingAs($seaActor)
            ->post('/freight/masters', $this->airPayload($branch->getKey()))
            ->assertForbidden();
    }

    public function test_a_master_cannot_depart_while_open(): void
    {
        $company = $this->createCompany('MMD');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['flights.view', 'flights.manage']);
        $this->actingAs($actor)->post('/freight/masters', $this->airPayload($branch->getKey()));
        $master = $this->findMaster($company);

        $this->actingAs($actor)
            ->post("/freight/masters/{$master->getKey()}/transitions", ['status' => 'departed'])
            ->assertSessionHasErrors('status');
    }

    public function test_a_master_cannot_depart_with_no_load_units(): void
    {
        $company = $this->createCompany('MME');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['flights.view', 'flights.manage']);
        $this->actingAs($actor)->post('/freight/masters', $this->airPayload($branch->getKey()));
        $master = $this->findMaster($company);

        $this->actingAs($actor)->post("/freight/masters/{$master->getKey()}/transitions", ['status' => 'closed']);

        $this->actingAs($actor)
            ->post("/freight/masters/{$master->getKey()}/transitions", ['status' => 'departed'])
            ->assertSessionHasErrors('status');
    }

    public function test_a_company_cannot_view_another_companys_master(): void
    {
        $companyA = $this->createCompany('MMF');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actorA = $this->createUser($companyA, $branchA);
        $this->grantPermissions($actorA, ['flights.view', 'flights.manage']);

        $companyB = $this->createCompany('MMG');
        $branchB = $this->createBranch($companyB, 'DXB');
        $actorB = $this->createUser($companyB, $branchB);
        $this->grantPermissions($actorB, ['flights.view', 'flights.manage']);
        $this->actingAs($actorB)->post('/freight/masters', $this->airPayload($branchB->getKey()));
        $masterB = $this->findMaster($companyB);

        $this->actingAs($actorA)->get("/freight/masters/{$masterB->getKey()}")->assertNotFound();
    }

    private function findMaster(Company $company): Master
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return Master::query()->latest('id')->firstOrFail();
        } finally {
            $context->forget();
        }
    }
}
