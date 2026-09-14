<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class ReportsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_without_reports_permission_cannot_view_the_shipments_report(): void
    {
        $company = $this->createCompany('RPA');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        $this->actingAs($actor)->get('/reports/shipments')->assertForbidden();
    }

    public function test_the_shipments_report_lists_shipments_in_the_date_range(): void
    {
        $company = $this->createCompany('RPB');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['reports.view', 'shipments.view']);
        $shipment = $this->createShipment($company, $branch);

        $response = $this->actingAs($actor)->get('/reports/shipments');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.tracking_number', $shipment->tracking_number)
            ->where('canExport', false));
    }

    public function test_a_user_without_export_permission_cannot_export_but_can_view(): void
    {
        $company = $this->createCompany('RPC');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['reports.view', 'shipments.view']);

        $this->actingAs($actor)->get('/reports/shipments')->assertOk();
        $this->actingAs($actor)->get('/reports/shipments/export')->assertForbidden();
    }

    public function test_a_user_with_export_permission_downloads_a_csv_with_the_correct_row(): void
    {
        $company = $this->createCompany('RPD');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['reports.view', 'reports.export', 'shipments.view']);
        $shipment = $this->createShipment($company, $branch);

        $response = $this->actingAs($actor)->get('/reports/shipments/export');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString($shipment->tracking_number, $response->streamedContent());
    }

    public function test_the_billing_report_totals_the_filtered_invoices(): void
    {
        $company = $this->createCompany('RPE');
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);
        $this->grantPermissions($actor, ['reports.view', 'billing.view']);
        $customer = $this->createCustomer($company, 'Report Customer', ['branch_id' => $branch->getKey()]);

        $this->withTenant($company, function () use ($branch, $customer): void {
            Invoice::query()->create([
                'branch_id' => $branch->getKey(),
                'customer_id' => $customer->getKey(),
                'invoice_number' => 'RPE-INV-000001',
                'status' => 'issued',
                'currency' => 'AED',
                'total' => 250,
                'amount_paid' => 100,
                'balance_due' => 150,
                'issue_date' => now()->toDateString(),
            ]);
        });

        $response = $this->actingAs($actor)->get('/reports/billing');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('totals.total', '250.00')
            ->where('totals.amountPaid', '100.00')
            ->where('totals.balanceDue', '150.00'));
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
}
