<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TenantContextQueueScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_context_does_not_leak_between_queued_jobs(): void
    {
        /** @var TenantContext $firstJobContext */
        $firstJobContext = $this->app->make(TenantContext::class);
        $firstJobContext->resolveCompany(1);

        self::assertSame(1, $firstJobContext->requireCompanyId());

        // Laravel's queue worker calls this after every job completes
        // (see Illuminate\Queue\Worker::runJob -> Application::forgetScopedInstances),
        // which is exactly what protects a long-running worker from leaking
        // one job's tenant into the next.
        $this->app->forgetScopedInstances();

        /** @var TenantContext $secondJobContext */
        $secondJobContext = $this->app->make(TenantContext::class);

        self::assertNotSame($firstJobContext, $secondJobContext);
        self::assertFalse($secondJobContext->isResolved());
    }
}
