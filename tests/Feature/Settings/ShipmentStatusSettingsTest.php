<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ShipmentStatusRole;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Models\User;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Companies define their own shipment workflow. What this pins down is the
 * line between what they may change and what the built-in modules still need
 * to be able to find — see ShipmentStatusRole.
 */
final class ShipmentStatusSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_new_company_starts_with_the_default_workflow(): void
    {
        $company = $this->createCompany('SSA');

        $statuses = $this->statusesOf($company);

        self::assertCount(10, $statuses);
        self::assertSame('draft', $statuses->firstWhere('is_initial', true)?->code);
        self::assertEqualsCanonicalizing(
            ['booked', 'cancelled'],
            $this->transitionCodesFrom($company, 'draft'),
        );
    }

    public function test_a_company_can_rename_and_recolour_a_built_in_status(): void
    {
        [$company, $actor] = $this->companyWithManager('SSB');
        $status = $this->findStatus($company, 'at_customs');

        $this->actingAs($actor)
            ->patch("/settings/shipment-statuses/{$status->getKey()}", [
                'name' => 'Sa Customs',
                'color' => 'violet',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $renamed = $this->findStatus($company, 'at_customs');

        self::assertSame('Sa Customs', $renamed->name);
        self::assertSame('violet', $renamed->color);

        // The code shipments store, and the role customs resolves by, are both
        // untouched by a rename.
        self::assertSame('at_customs', $renamed->code);
        self::assertSame(ShipmentStatusRole::AtCustoms, $renamed->role);
    }

    public function test_a_custom_status_can_be_added_and_slotted_into_the_flow(): void
    {
        [$company, $actor] = $this->companyWithManager('SSC');
        $delivered = $this->findStatus($company, 'delivered');

        $this->actingAs($actor)
            ->post('/settings/shipment-statuses', [
                'name' => 'Awaiting payment',
                'color' => 'amber',
                'transitions_to' => [$delivered->getKey()],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $created = $this->statusesOf($company)->firstWhere('name', 'Awaiting payment');

        self::assertNotNull($created);
        self::assertSame('awaiting_payment', $created->code);
        self::assertNull($created->role, 'A company-defined status carries no system behaviour.');
        self::assertSame(['delivered'], $this->transitionCodesFrom($company, 'awaiting_payment'));
    }

    public function test_a_custom_status_can_be_reached_and_used_in_operations(): void
    {
        [$company, $actor] = $this->companyWithManager('SSD');
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.view', 'tracking.update']);
        $branch = $this->createBranch($company, 'DXB');

        $booked = $this->findStatus($company, 'booked');

        // A new status, reachable from booked.
        $this->actingAs($actor)->post('/settings/shipment-statuses', [
            'name' => 'Awaiting payment',
            'color' => 'amber',
        ])->assertSessionHasNoErrors();

        $custom = $this->statusesOf($company)->firstWhere('code', 'awaiting_payment');

        $this->actingAs($actor)->patch("/settings/shipment-statuses/{$booked->getKey()}", [
            'name' => $booked->name,
            'color' => $booked->color,
            'transitions_to' => [$custom->getKey()],
        ])->assertSessionHasNoErrors();

        $shipment = $this->createShipment($company, $branch, ['status' => 'booked']);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/transitions", ['status' => 'awaiting_payment'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->withTenant($company, function () use ($shipment): void {
            self::assertSame('awaiting_payment', $shipment->refresh()->status);
        });
    }

    public function test_a_transition_the_company_has_not_allowed_is_rejected(): void
    {
        [$company, $actor] = $this->companyWithManager('SSE');
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage', 'tracking.view', 'tracking.update']);
        $branch = $this->createBranch($company, 'DXB');

        $booked = $this->findStatus($company, 'booked');

        // Narrow the flow: booked may now only be cancelled, not received.
        $this->actingAs($actor)->patch("/settings/shipment-statuses/{$booked->getKey()}", [
            'name' => $booked->name,
            'color' => $booked->color,
            'transitions_to' => [$this->findStatus($company, 'cancelled')->getKey()],
        ])->assertSessionHasNoErrors();

        $shipment = $this->createShipment($company, $branch, ['status' => 'booked']);

        $this->actingAs($actor)
            ->post("/shipments/{$shipment->getKey()}/transitions", ['status' => 'received'])
            ->assertSessionHasErrors('status');

        $this->withTenant($company, function () use ($shipment): void {
            self::assertSame('booked', $shipment->refresh()->status);
        });
    }

    public function test_a_status_a_module_drives_shipments_into_cannot_be_deleted_or_switched_off(): void
    {
        [$company, $actor] = $this->companyWithManager('SSF');
        $atCustoms = $this->findStatus($company, 'at_customs');

        $this->actingAs($actor)
            ->delete("/settings/shipment-statuses/{$atCustoms->getKey()}")
            ->assertSessionHasErrors('status');

        $this->actingAs($actor)
            ->patch("/settings/shipment-statuses/{$atCustoms->getKey()}", [
                'name' => $atCustoms->name,
                'color' => $atCustoms->color,
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        self::assertNotNull($this->findStatus($company, 'at_customs'));
    }

    public function test_a_status_shipments_are_sitting_in_cannot_be_deleted(): void
    {
        [$company, $actor] = $this->companyWithManager('SSG');
        $branch = $this->createBranch($company, 'DXB');

        $this->actingAs($actor)->post('/settings/shipment-statuses', ['name' => 'On hold', 'color' => 'amber'])->assertSessionHasNoErrors();
        $custom = $this->statusesOf($company)->firstWhere('code', 'on_hold');

        $this->createShipment($company, $branch, ['status' => 'on_hold']);

        $this->actingAs($actor)
            ->delete("/settings/shipment-statuses/{$custom->getKey()}")
            ->assertSessionHasErrors('status');
    }

    public function test_an_unused_custom_status_can_be_deleted(): void
    {
        [$company, $actor] = $this->companyWithManager('SSH');

        $this->actingAs($actor)->post('/settings/shipment-statuses', ['name' => 'On hold', 'color' => 'amber'])->assertSessionHasNoErrors();
        $custom = $this->statusesOf($company)->firstWhere('code', 'on_hold');

        $this->actingAs($actor)
            ->delete("/settings/shipment-statuses/{$custom->getKey()}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        self::assertNull($this->statusesOf($company)->firstWhere('code', 'on_hold'));
    }

    public function test_marking_a_status_as_the_starting_one_clears_the_previous(): void
    {
        [$company, $actor] = $this->companyWithManager('SSI');
        $booked = $this->findStatus($company, 'booked');

        $this->actingAs($actor)
            ->patch("/settings/shipment-statuses/{$booked->getKey()}", [
                'name' => $booked->name,
                'color' => $booked->color,
                'is_initial' => true,
            ])
            ->assertSessionHasNoErrors();

        $initials = $this->statusesOf($company)->where('is_initial', true);

        self::assertCount(1, $initials);
        self::assertSame('booked', $initials->first()->code);
    }

    public function test_a_user_without_the_manage_permission_cannot_change_the_workflow(): void
    {
        $company = $this->createCompany('SSJ');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['statuses.view']);

        $this->actingAs($actor)->get('/settings/shipment-statuses')->assertOk();

        $this->actingAs($actor)
            ->post('/settings/shipment-statuses', ['name' => 'On hold', 'color' => 'amber'])
            ->assertForbidden();
    }

    public function test_a_user_without_any_status_permission_cannot_open_the_screen(): void
    {
        $company = $this->createCompany('SSK');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['shipments.view']);

        $this->actingAs($actor)->get('/settings/shipment-statuses')->assertForbidden();
    }

    public function test_a_company_cannot_edit_another_companys_status(): void
    {
        [, $actor] = $this->companyWithManager('SSL');
        $otherCompany = $this->createCompany('SSM');
        $foreign = $this->findStatus($otherCompany, 'booked');

        $this->actingAs($actor)
            ->patch("/settings/shipment-statuses/{$foreign->getKey()}", ['name' => 'Hijacked', 'color' => 'rose'])
            ->assertNotFound();
    }

    public function test_each_company_keeps_its_own_workflow(): void
    {
        [$companyA, $actorA] = $this->companyWithManager('SSN');
        $companyB = $this->createCompany('SSO');

        $this->actingAs($actorA)
            ->patch("/settings/shipment-statuses/{$this->findStatus($companyA, 'delivered')->getKey()}", [
                'name' => 'Naihatid',
                'color' => 'emerald',
            ])
            ->assertSessionHasNoErrors();

        self::assertSame('Naihatid', $this->findStatus($companyA, 'delivered')->name);
        self::assertSame('Delivered', $this->findStatus($companyB, 'delivered')->name);
    }

    /** @return array{Company, User} */
    private function companyWithManager(string $code): array
    {
        $company = $this->createCompany($code);
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['statuses.view', 'statuses.manage']);

        return [$company, $actor];
    }

    private function statusesOf(Company $company): Collection
    {
        return $this->withTenant($company, fn () => ShipmentStatus::query()->ordered()->get());
    }

    private function findStatus(Company $company, string $code): ShipmentStatus
    {
        return $this->withTenant($company, fn () => ShipmentStatus::query()->where('code', $code)->firstOrFail());
    }

    /** @return list<string> */
    private function transitionCodesFrom(Company $company, string $code): array
    {
        return $this->withTenant($company, function () use ($company, $code): array {
            $repository = app(ShipmentStatusRepository::class);
            $repository->forget();

            $from = $repository->byCode($code, (int) $company->getKey());

            return $from === null ? [] : $repository->allowedFrom($from)->map(fn (ShipmentStatus $s): string => $s->code)->all();
        });
    }

    private function withTenant(Company $company, \Closure $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }

    private function createShipment(Company $company, $branch, array $overrides = []): Shipment
    {
        return $this->withTenant($company, fn () => Shipment::query()->create([
            'branch_id' => $branch->getKey(),
            'tracking_number' => $company->code.'-'.uniqid(),
            'mode' => 'air',
            'status' => 'draft',
            'destination_country_code' => 'PH',
            'currency' => 'AED',
            ...$overrides,
        ]));
    }
}
