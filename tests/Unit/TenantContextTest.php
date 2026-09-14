<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Tenancy\TenantContext;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    #[Test]
    public function it_rejects_access_before_the_context_is_resolved(): void
    {
        $this->expectException(LogicException::class);

        (new TenantContext)->companyId();
    }

    #[Test]
    public function it_resolves_and_forgets_a_company(): void
    {
        $context = new TenantContext;
        $context->resolveCompany(42);

        self::assertSame(42, $context->requireCompanyId());
        self::assertFalse($context->isPlatformBypass());

        $context->forget();

        self::assertFalse($context->isResolved());
    }

    #[Test]
    public function platform_bypass_is_explicit_and_cannot_be_used_as_a_company(): void
    {
        $context = new TenantContext;
        $context->resolvePlatformBypass();

        self::assertTrue($context->isPlatformBypass());
        $this->expectException(LogicException::class);

        $context->requireCompanyId();
    }
}
