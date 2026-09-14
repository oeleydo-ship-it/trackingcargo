<?php

declare(strict_types=1);

namespace Tests\Feature\Customs;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomsClearance;
use App\Models\CustomsDuty;
use App\Models\CustomsInspection;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomsDutyAndInspectionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_duty_can_be_assessed_and_marked_paid(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CDA', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $clearance = $this->openClearance($company, $branch, $actor);

        $this->actingAs($actor)
            ->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/duties", [
                'type' => 'duty', 'description' => 'Import duty', 'amount' => 150.50, 'currency' => 'AED',
            ])
            ->assertRedirect();

        $duty = $this->dutyFor($company, $clearance->getKey());
        self::assertFalse($duty->is_paid);

        $this->actingAs($actor)
            ->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/duties/{$duty->getKey()}/pay")
            ->assertRedirect();

        $this->withTenant($company, function () use ($duty): void {
            $duty->refresh();
            self::assertTrue($duty->is_paid);
            self::assertNotNull($duty->paid_at);
        });
    }

    public function test_marking_an_already_paid_duty_paid_again_is_a_safe_no_op(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CDB', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $clearance = $this->openClearance($company, $branch, $actor);
        $this->actingAs($actor)->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/duties", [
            'type' => 'tax', 'description' => 'VAT', 'amount' => 25, 'currency' => 'AED',
        ]);
        $duty = $this->dutyFor($company, $clearance->getKey());

        $this->actingAs($actor)->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/duties/{$duty->getKey()}/pay");
        $firstPaidAt = $this->withTenantReturn($company, fn () => CustomsDuty::query()->findOrFail($duty->getKey())->paid_at);

        $this->actingAs($actor)
            ->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/duties/{$duty->getKey()}/pay")
            ->assertRedirect();

        $secondPaidAt = $this->withTenantReturn($company, fn () => CustomsDuty::query()->findOrFail($duty->getKey())->paid_at);
        self::assertEquals($firstPaidAt, $secondPaidAt);
    }

    public function test_an_inspection_can_be_scheduled_and_completed(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CDC', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $clearance = $this->openClearance($company, $branch, $actor);

        $this->actingAs($actor)
            ->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/inspections", ['type' => 'physical'])
            ->assertRedirect();

        $inspection = $this->inspectionFor($company, $clearance->getKey());

        $this->actingAs($actor)
            ->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/inspections/{$inspection->getKey()}/complete", [
                'status' => 'passed',
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($inspection): void {
            $inspection->refresh();
            self::assertSame('passed', $inspection->status->value);
            self::assertNotNull($inspection->completed_at);
        });
    }

    public function test_an_already_completed_inspection_cannot_be_completed_again(): void
    {
        [$company, $branch, $actor] = $this->setUpTenant('CDD', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $clearance = $this->openClearance($company, $branch, $actor);
        $this->actingAs($actor)->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/inspections", ['type' => 'xray']);
        $inspection = $this->inspectionFor($company, $clearance->getKey());
        $this->actingAs($actor)->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/inspections/{$inspection->getKey()}/complete", ['status' => 'failed']);

        $this->actingAs($actor)
            ->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/inspections/{$inspection->getKey()}/complete", ['status' => 'passed'])
            ->assertSessionHasErrors('status');
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

    private function openClearance(Company $company, Branch $branch, User $actor): CustomsClearance
    {
        $shipment = $this->createShipment($company, $branch, ['status' => 'in_transit']);
        $this->actingAs($actor)->post("/shipments/{$shipment->getKey()}/customs-clearances", []);

        return $this->withTenantReturn($company, fn () => CustomsClearance::query()->where('shipment_id', $shipment->getKey())->firstOrFail());
    }

    private function dutyFor(Company $company, int $clearanceId): CustomsDuty
    {
        return $this->withTenantReturn($company, fn () => CustomsDuty::query()->where('customs_clearance_id', $clearanceId)->firstOrFail());
    }

    private function inspectionFor(Company $company, int $clearanceId): CustomsInspection
    {
        return $this->withTenantReturn($company, fn () => CustomsInspection::query()->where('customs_clearance_id', $clearanceId)->firstOrFail());
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
