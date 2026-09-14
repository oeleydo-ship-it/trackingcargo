<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Box;
use App\Models\Company;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class BoxesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_create_a_box_and_add_sizes_to_it(): void
    {
        $company = $this->createCompany('BXA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        $this->actingAs($actor)->post('/settings/boxes', ['name' => 'Standard Box'])->assertRedirect();
        $box = $this->withTenant($company, fn () => Box::query()->where('name', 'Standard Box')->firstOrFail());

        $this->actingAs($actor)
            ->post("/settings/boxes/{$box->getKey()}/sizes", ['name' => 'Jumbo', 'length_cm' => 80, 'width_cm' => 60, 'height_cm' => 50])
            ->assertRedirect();

        $response = $this->actingAs($actor)->get('/settings/boxes');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('boxes', 1)
            ->where('boxes.0.name', 'Standard Box')
            ->has('boxes.0.sizes', 1)
            ->where('boxes.0.sizes.0.name', 'Jumbo'));
    }

    public function test_a_user_without_shipments_manage_cannot_create_a_box_or_a_size(): void
    {
        $company = $this->createCompany('BXB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view']);
        $box = $this->withTenant($company, fn () => Box::query()->create(['name' => 'Standard Box']));

        $this->actingAs($actor)->post('/settings/boxes', ['name' => 'Crate'])->assertForbidden();
        $this->actingAs($actor)
            ->post("/settings/boxes/{$box->getKey()}/sizes", ['name' => 'Jumbo', 'length_cm' => 80, 'width_cm' => 60, 'height_cm' => 50])
            ->assertForbidden();
    }

    public function test_a_user_without_shipments_view_cannot_list_boxes(): void
    {
        $company = $this->createCompany('BXC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)->get('/settings/boxes')->assertForbidden();
    }

    public function test_two_sizes_on_the_same_box_cannot_share_a_name(): void
    {
        $company = $this->createCompany('BXD');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        $box = $this->withTenant($company, function () {
            $box = Box::query()->create(['name' => 'Standard Box']);
            $box->sizes()->create(['name' => 'Jumbo', 'length_cm' => 80, 'width_cm' => 60, 'height_cm' => 50]);

            return $box;
        });

        $this->actingAs($actor)
            ->post("/settings/boxes/{$box->getKey()}/sizes", ['name' => 'Jumbo', 'length_cm' => 90, 'width_cm' => 70, 'height_cm' => 60])
            ->assertSessionHasErrors('name');
    }

    public function test_a_user_can_deactivate_and_reactivate_a_box_and_a_size(): void
    {
        $company = $this->createCompany('BXE');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);
        [$box, $size] = $this->withTenant($company, function () {
            $box = Box::query()->create(['name' => 'Standard Box']);
            $size = $box->sizes()->create(['name' => 'Jumbo', 'length_cm' => 80, 'width_cm' => 60, 'height_cm' => 50]);

            return [$box, $size];
        });

        $this->actingAs($actor)->post("/settings/boxes/{$box->getKey()}/active", ['is_active' => false])->assertRedirect();
        $this->actingAs($actor)->post("/settings/boxes/{$box->getKey()}/sizes/{$size->getKey()}/active", ['is_active' => false])->assertRedirect();

        $this->withTenant($company, function () use ($box, $size): void {
            $box->refresh();
            $size->refresh();
            self::assertFalse($box->is_active);
            self::assertFalse($size->is_active);
        });
    }

    public function test_a_company_cannot_manage_another_companys_box(): void
    {
        $companyA = $this->createCompany('BXF');
        $branchA = $this->createBranch($companyA, 'DXB');
        $actorA = $this->createUser($companyA, $branchA);
        $this->grantPermissions($actorA, ['shipments.view', 'shipments.manage']);

        $companyB = $this->createCompany('BXG');
        $boxB = $this->withTenant($companyB, fn () => Box::query()->create(['name' => 'Standard Box']));

        $this->actingAs($actorA)
            ->patch("/settings/boxes/{$boxB->getKey()}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->actingAs($actorA)
            ->post("/settings/boxes/{$boxB->getKey()}/sizes", ['name' => 'Jumbo', 'length_cm' => 80, 'width_cm' => 60, 'height_cm' => 50])
            ->assertNotFound();
    }

    private function withTenant(Company $company, Closure $callback): mixed
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
