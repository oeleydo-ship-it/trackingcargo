<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\RateCard;
use App\Services\Billing\RateCalculatorService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class RateCardAndCalculatorTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_create_a_rate_card_with_tiers(): void
    {
        $company = $this->createCompany('RCA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['rates.view', 'rates.manage']);

        $this->actingAs($actor)
            ->post('/rate-cards', ['name' => 'Standard Air', 'currency' => 'AED', 'base_fee' => 10, 'min_charge' => 20])
            ->assertRedirect();

        $rateCard = $this->withTenantReturn($company, fn () => RateCard::query()->where('name', 'Standard Air')->firstOrFail());

        $this->actingAs($actor)
            ->post("/rate-cards/{$rateCard->getKey()}/tiers", ['min_weight_kg' => 0, 'max_weight_kg' => 10, 'price_per_kg' => 5])
            ->assertRedirect();

        $this->actingAs($actor)
            ->post("/rate-cards/{$rateCard->getKey()}/tiers", ['min_weight_kg' => 10, 'price_per_kg' => 4])
            ->assertRedirect();

        $this->withTenant($company, function () use ($rateCard): void {
            self::assertSame(2, $rateCard->tiers()->count());
        });
    }

    public function test_overlapping_tiers_are_rejected(): void
    {
        $company = $this->createCompany('RCB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['rates.view', 'rates.manage']);
        $this->actingAs($actor)->post('/rate-cards', ['name' => 'Standard', 'currency' => 'AED']);
        $rateCard = $this->withTenantReturn($company, fn () => RateCard::query()->where('name', 'Standard')->firstOrFail());
        $this->actingAs($actor)->post("/rate-cards/{$rateCard->getKey()}/tiers", ['min_weight_kg' => 0, 'max_weight_kg' => 10, 'price_per_kg' => 5]);

        $this->actingAs($actor)
            ->post("/rate-cards/{$rateCard->getKey()}/tiers", ['min_weight_kg' => 5, 'max_weight_kg' => 15, 'price_per_kg' => 4])
            ->assertSessionHasErrors('min_weight_kg');
    }

    public function test_the_calculator_picks_the_matching_tier_and_clamps_to_the_minimum_charge(): void
    {
        $company = $this->createCompany('RCC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['rates.view', 'rates.manage']);
        $this->actingAs($actor)->post('/rate-cards', ['name' => 'Standard', 'currency' => 'AED', 'base_fee' => 5, 'min_charge' => 20]);
        $rateCard = $this->withTenantReturn($company, fn () => RateCard::query()->where('name', 'Standard')->firstOrFail());
        $this->actingAs($actor)->post("/rate-cards/{$rateCard->getKey()}/tiers", ['min_weight_kg' => 0, 'max_weight_kg' => 10, 'price_per_kg' => 5]);
        $this->actingAs($actor)->post("/rate-cards/{$rateCard->getKey()}/tiers", ['min_weight_kg' => 10, 'price_per_kg' => 3]);

        $calculator = app(RateCalculatorService::class);

        $this->withTenant($company, function () use ($calculator, $rateCard): void {
            $rateCard->load('tiers');

            // 2kg: base 5 + 2*5 = 15, clamped up to the 20 minimum charge.
            $light = $calculator->calculate($rateCard, 2.0);
            self::assertEqualsWithDelta(20.0, $light['amount'], 0.001);

            // 20kg: falls into the 10+ tier, base 5 + 20*3 = 65, above the minimum.
            $heavy = $calculator->calculate($rateCard, 20.0);
            self::assertEqualsWithDelta(65.0, $heavy['amount'], 0.001);
        });
    }

    private function withTenant(Company $company, Closure $callback): void
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            $callback();
        } finally {
            $context->forget();
        }
    }

    private function withTenantReturn(Company $company, Closure $callback): mixed
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
