<?php

declare(strict_types=1);

namespace Tests\Feature\Customs;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomsClearance;
use App\Models\Document;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class DocumentTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_document_can_be_uploaded_and_downloaded(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor] = $this->setUpTenant('DCA', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $clearance = $this->openClearance($company, $branch, $actor);

        $file = UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf');

        $this->actingAs($actor)
            ->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/documents", [
                'category' => 'commercial_invoice', 'file' => $file,
            ])
            ->assertRedirect();

        $document = $this->documentFor($company, $clearance->getKey());
        self::assertSame('invoice.pdf', $document->original_filename);
        self::assertNotSame('invoice.pdf', basename($document->path));
        Storage::disk('local')->assertExists($document->path);

        $this->actingAs($actor)
            ->get("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/documents/{$document->getKey()}/download")
            ->assertOk();
    }

    public function test_a_user_from_another_company_cannot_download_the_document(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor] = $this->setUpTenant('DCB', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $clearance = $this->openClearance($company, $branch, $actor);
        $this->actingAs($actor)->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/documents", [
            'category' => 'commercial_invoice', 'file' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ]);
        $document = $this->documentFor($company, $clearance->getKey());

        [$otherCompany, $otherBranch, $otherActor] = $this->setUpTenant('DCC', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);

        $this->actingAs($otherActor)
            ->get("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/documents/{$document->getKey()}/download")
            ->assertNotFound();
    }

    public function test_a_document_can_be_deleted(): void
    {
        Storage::fake('local');
        [$company, $branch, $actor] = $this->setUpTenant('DCD', ['customs.view', 'customs.manage', 'shipments.view', 'tracking.update']);
        $clearance = $this->openClearance($company, $branch, $actor);
        $this->actingAs($actor)->post("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/documents", [
            'category' => 'other', 'file' => UploadedFile::fake()->create('note.pdf', 5, 'application/pdf'),
        ]);
        $document = $this->documentFor($company, $clearance->getKey());

        $this->actingAs($actor)
            ->delete("/shipments/{$clearance->shipment_id}/customs-clearances/{$clearance->getKey()}/documents/{$document->getKey()}")
            ->assertRedirect();

        Storage::disk('local')->assertMissing($document->path);
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

    private function documentFor(Company $company, int $clearanceId): Document
    {
        return $this->withTenantReturn($company, fn () => Document::query()
            ->where('documentable_type', CustomsClearance::class)
            ->where('documentable_id', $clearanceId)
            ->firstOrFail());
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
