<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->context->forget();
        parent::tearDown();
    }

    public function test_tenant_scope_only_returns_the_active_company_records(): void
    {
        [$companyA, $companyB] = $this->companies();
        $branchA = $this->createBranch($companyA, 'DXB');
        $this->createBranch($companyB, 'MNL');

        $this->context->resolveCompany((int) $companyA->getKey());

        self::assertSame([$branchA->getKey()], Branch::query()->pluck('id')->all());
    }

    public function test_tenant_query_fails_closed_without_a_resolved_context(): void
    {
        $this->expectException(LogicException::class);

        Branch::query()->count();
    }

    public function test_tenant_creation_rejects_a_conflicting_company_identifier(): void
    {
        [$companyA, $companyB] = $this->companies();
        $this->context->resolveCompany((int) $companyA->getKey());

        $branch = new Branch;
        $branch->forceFill([
            'company_id' => $companyB->getKey(),
            'code' => 'BAD',
            'name' => 'Wrong tenant',
            'tracking_prefix' => 'BAD',
            'country_code' => 'AE',
            'city' => 'Dubai',
        ]);

        $this->expectException(LogicException::class);
        $branch->save();
    }

    private function companies(): array
    {
        return [
            Company::query()->create(['code' => 'AAA', 'name' => 'Company A', 'slug' => 'company-a', 'country_code' => 'AE']),
            Company::query()->create(['code' => 'BBB', 'name' => 'Company B', 'slug' => 'company-b', 'country_code' => 'PH']),
        ];
    }

    private function createBranch(Company $company, string $code): Branch
    {
        $this->context->resolveCompany((int) $company->getKey());

        return Branch::query()->create([
            'code' => $code,
            'name' => $code.' Branch',
            'tracking_prefix' => $code,
            'country_code' => $company->country_code,
            'city' => $code === 'DXB' ? 'Dubai' : 'Manila',
        ]);
    }
}
