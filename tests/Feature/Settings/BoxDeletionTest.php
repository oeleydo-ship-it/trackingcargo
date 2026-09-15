<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Box;
use App\Models\BoxSize;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Deleting boxes and sizes from Settings → Boxes: allowed while nothing has
 * been booked with them, refused (in favour of deactivating) once a package
 * points at one.
 */
final class BoxDeletionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_an_unused_box_is_deleted_with_its_sizes(): void
    {
        [$company, $actor] = $this->manager('BDA');
        $box = $this->makeBox($company, 'Big Box', ['Small', 'Large']);

        $this->actingAs($actor)
            ->delete("/settings/boxes/{$box->getKey()}")
            ->assertRedirect()
            ->assertSessionHas('success');

        self::assertSame(0, DB::table('boxes')->where('id', $box->getKey())->count());
        self::assertSame(0, DB::table('box_sizes')->where('box_id', $box->getKey())->count());
    }

    public function test_a_box_used_on_a_shipment_cannot_be_deleted(): void
    {
        [$company, $actor, $branch] = $this->manager('BDB');
        $box = $this->makeBox($company, 'Standard Box', ['Regular']);
        $this->bookWith($company, $branch, $box->sizes->first());

        $this->actingAs($actor)
            ->delete("/settings/boxes/{$box->getKey()}")
            ->assertRedirect()
            ->assertSessionHas('error', '"Standard Box" has been used on shipments, so it can\'t be deleted. Deactivate it instead to stop it being offered.');

        self::assertSame(1, DB::table('boxes')->where('id', $box->getKey())->whereNull('deleted_at')->count());
    }

    public function test_a_box_used_only_by_a_deleted_shipment_still_cannot_be_deleted(): void
    {
        [$company, $actor, $branch] = $this->manager('BDC');
        $box = $this->makeBox($company, 'Standard Box', ['Regular']);
        $shipment = $this->bookWith($company, $branch, $box->sizes->first());

        // Deleted shipments can be restored, and must still show their box.
        $this->withTenant($company, fn () => $shipment->delete());

        $this->actingAs($actor)->delete("/settings/boxes/{$box->getKey()}")->assertSessionHas('error');

        self::assertSame(1, DB::table('boxes')->where('id', $box->getKey())->count());
    }

    public function test_an_unused_size_is_deleted_and_its_name_can_be_reused(): void
    {
        [$company, $actor] = $this->manager('BDD');
        $box = $this->makeBox($company, 'Standard Box', ['Regular', 'Jumbo']);
        $jumbo = $box->sizes->firstWhere('name', 'Jumbo');

        $this->actingAs($actor)
            ->delete("/settings/boxes/{$box->getKey()}/sizes/{$jumbo->getKey()}")
            ->assertRedirect()
            ->assertSessionHas('success');

        self::assertSame(0, DB::table('box_sizes')->where('id', $jumbo->getKey())->count());

        // Removed outright, so the name is free again.
        $this->actingAs($actor)
            ->post("/settings/boxes/{$box->getKey()}/sizes", ['name' => 'Jumbo', 'length_cm' => 90, 'width_cm' => 70, 'height_cm' => 60])
            ->assertSessionHasNoErrors();
    }

    public function test_a_size_used_on_a_shipment_cannot_be_deleted_but_its_unused_sibling_can(): void
    {
        [$company, $actor, $branch] = $this->manager('BDE');
        $box = $this->makeBox($company, 'Standard Box', ['Regular', 'Jumbo']);
        $regular = $box->sizes->firstWhere('name', 'Regular');
        $jumbo = $box->sizes->firstWhere('name', 'Jumbo');
        $this->bookWith($company, $branch, $regular);

        $this->actingAs($actor)
            ->delete("/settings/boxes/{$box->getKey()}/sizes/{$regular->getKey()}")
            ->assertSessionHas('error');

        $this->actingAs($actor)
            ->delete("/settings/boxes/{$box->getKey()}/sizes/{$jumbo->getKey()}")
            ->assertSessionHas('success');

        self::assertSame(1, DB::table('box_sizes')->where('id', $regular->getKey())->count());
        self::assertSame(0, DB::table('box_sizes')->where('id', $jumbo->getKey())->count());
    }

    public function test_the_page_reports_how_many_packages_use_each_size(): void
    {
        [$company, $actor, $branch] = $this->manager('BDF');
        $box = $this->makeBox($company, 'Standard Box', ['Regular']);
        $this->bookWith($company, $branch, $box->sizes->first());

        $this->actingAs($actor)
            ->get('/settings/boxes')
            ->assertInertia(fn ($page) => $page->where('boxes.0.sizes.0.packages_count', 1));
    }

    public function test_a_user_without_shipments_manage_cannot_delete(): void
    {
        $company = $this->createCompany('BDG');
        $viewer = $this->createUser($company);
        $this->grantPermissions($viewer, ['shipments.view']);
        $box = $this->makeBox($company, 'Standard Box', ['Regular']);

        $this->actingAs($viewer)->delete("/settings/boxes/{$box->getKey()}")->assertForbidden();
        $this->actingAs($viewer)->delete("/settings/boxes/{$box->getKey()}/sizes/{$box->sizes->first()->getKey()}")->assertForbidden();

        self::assertSame(1, DB::table('boxes')->where('id', $box->getKey())->count());
    }

    public function test_a_company_cannot_delete_another_companys_box(): void
    {
        [, $actor] = $this->manager('BDH');
        $otherCompany = $this->createCompany('BDI');
        $foreign = $this->makeBox($otherCompany, 'Their Box', []);

        $this->actingAs($actor)->delete("/settings/boxes/{$foreign->getKey()}")->assertNotFound();

        self::assertSame(1, DB::table('boxes')->where('id', $foreign->getKey())->count());
    }

    public function test_a_size_cannot_be_deleted_through_a_different_box(): void
    {
        [$company, $actor] = $this->manager('BDJ');
        $first = $this->makeBox($company, 'First Box', ['Regular']);
        $second = $this->makeBox($company, 'Second Box', []);

        $this->actingAs($actor)
            ->delete("/settings/boxes/{$second->getKey()}/sizes/{$first->sizes->first()->getKey()}")
            ->assertNotFound();
    }

    /** @return array{Company, User, Branch} */
    private function manager(string $code): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['shipments.view', 'shipments.manage']);

        return [$company, $actor, $branch];
    }

    /** @param list<string> $sizeNames */
    private function makeBox(Company $company, string $name, array $sizeNames): Box
    {
        return $this->withTenant($company, function () use ($name, $sizeNames): Box {
            $box = Box::query()->create(['name' => $name, 'is_active' => true]);

            foreach ($sizeNames as $sizeName) {
                BoxSize::query()->create(['box_id' => $box->getKey(), 'name' => $sizeName, 'length_cm' => 30, 'width_cm' => 30, 'height_cm' => 30, 'is_active' => true]);
            }

            return $box->load('sizes');
        });
    }

    private function bookWith(Company $company, Branch $branch, BoxSize $size)
    {
        $shipment = $this->createShipment($company, $branch);

        DB::table('shipment_packages')->where('shipment_id', $shipment->getKey())->update(['box_size_id' => $size->getKey()]);

        return $shipment;
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
