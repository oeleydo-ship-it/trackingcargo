<?php

declare(strict_types=1);

namespace Tests\Feature\Numbering;

use App\Services\Numbering\NumberSequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class NumberSequenceServiceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_it_allocates_sequential_gapless_numbers_within_a_scope(): void
    {
        $company = $this->createCompany('NSA');
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        $service = app(NumberSequenceService::class);

        try {
            $numbers = [];
            for ($i = 0; $i < 25; $i++) {
                $numbers[] = $service->next('customer');
            }
        } finally {
            $context->forget();
        }

        self::assertSame(range(1, 25), $numbers);
    }

    public function test_it_keeps_independent_counters_per_document_type(): void
    {
        $company = $this->createCompany('NSB');
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        $service = app(NumberSequenceService::class);

        try {
            self::assertSame(1, $service->next('customer'));
            self::assertSame(1, $service->next('invoice'));
            self::assertSame(2, $service->next('customer'));
            self::assertSame(2, $service->next('invoice'));
        } finally {
            $context->forget();
        }
    }

    public function test_it_keeps_independent_counters_per_company(): void
    {
        $companyA = $this->createCompany('NSC');
        $companyB = $this->createCompany('NSD');
        $context = app(TenantContext::class);
        $service = app(NumberSequenceService::class);

        $context->resolveCompany((int) $companyA->getKey());
        $first = $service->next('customer');
        $context->forget();

        $context->resolveCompany((int) $companyB->getKey());
        $second = $service->next('customer');
        $context->forget();

        self::assertSame(1, $first);
        self::assertSame(1, $second);
    }

    public function test_it_keeps_independent_counters_per_branch(): void
    {
        $company = $this->createCompany('NSE');
        $branchA = $this->createBranch($company, 'BRX');
        $branchB = $this->createBranch($company, 'BRY');
        $context = app(TenantContext::class);
        $service = app(NumberSequenceService::class);

        $context->resolveCompany((int) $company->getKey());
        try {
            self::assertSame(1, $service->next('shipment', branchId: (int) $branchA->getKey()));
            self::assertSame(1, $service->next('shipment', branchId: (int) $branchB->getKey()));
            self::assertSame(2, $service->next('shipment', branchId: (int) $branchA->getKey()));
            self::assertSame(1, $service->next('shipment'));
        } finally {
            $context->forget();
        }
    }
}
