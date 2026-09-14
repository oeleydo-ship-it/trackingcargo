<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CustomerImportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_import_customers_from_csv(): void
    {
        $company = $this->createCompany('IMA');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);

        $csv = "name,type,company_name,email,phone,tax_id,identification_number\n"
            ."Imported One,individual,,one@example.test,,,\n"
            ."Imported Two,business,Second Co,two@example.test,+971500000001,TRN-2,\n";

        $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);

        $this->actingAs($actor)
            ->post('/crm/customers/import', ['file' => $file])
            ->assertRedirect('/crm/customers');

        $this->assertDatabaseHas('customers', ['company_id' => $company->getKey(), 'name' => 'Imported One']);
        $this->assertDatabaseHas('customers', ['company_id' => $company->getKey(), 'name' => 'Imported Two', 'company_name' => 'Second Co']);
    }

    public function test_invalid_rows_are_skipped_and_reported_without_blocking_valid_rows(): void
    {
        $company = $this->createCompany('IMB');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['customers.view', 'customers.manage']);

        $csv = "name,type,company_name,email,phone,tax_id,identification_number\n"
            .",individual,,,,,\n" // missing required name
            ."Valid Row,individual,,,,,\n"
            ."Bad Type Row,not-a-real-type,,,,,\n";

        $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);

        $response = $this->actingAs($actor)->post('/crm/customers/import', ['file' => $file]);

        $response->assertRedirect('/crm/customers');
        $response->assertSessionHas('error');

        $context = app(TenantContext::class);
        $context->resolveCompany((int) $company->getKey());
        try {
            self::assertSame(1, Customer::query()->count());
        } finally {
            $context->forget();
        }
    }

    public function test_a_user_without_permission_cannot_import_customers(): void
    {
        $company = $this->createCompany('IMC');
        $actor = $this->createUser($company);

        $csv = "name,type\nSomeone,individual\n";
        $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);

        $this->actingAs($actor)
            ->post('/crm/customers/import', ['file' => $file])
            ->assertForbidden();
    }
}
