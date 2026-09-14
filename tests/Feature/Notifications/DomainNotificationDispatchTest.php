<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ShipmentStatusRole;
use App\Enums\InvoiceStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\InvoiceIssuedNotification;
use App\Notifications\ShipmentDeliveredNotification;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\InvoiceTransitionService;
use App\Services\Shipments\ShipmentTransitionService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Proves the two domain services that got a notification hook this phase
 * — ShipmentTransitionService (on delivered) and InvoiceTransitionService
 * (on issued) — actually notify the customer's linked portal user, not
 * just that the notification class itself is well-formed.
 */
final class DomainNotificationDispatchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_shipment_reaching_delivered_notifies_the_customers_portal_user(): void
    {
        Notification::fake();

        [$company, $branch, $actor] = $this->setUpTenant('DNA');
        $portalUser = $this->createUser($company, $branch);
        $customer = $this->createCustomerWithPortalUser($company, 'DNA-C-000001', $portalUser);
        $shipment = $this->createShipment($company, $branch, ['customer_id' => $customer->getKey(), 'status' => 'out_for_delivery']);

        $this->withTenant($company, function () use ($shipment, $actor): void {
            app(ShipmentTransitionService::class)->transitionToRole($shipment, ShipmentStatusRole::Delivered, $actor);
        });

        Notification::assertSentTo($portalUser, ShipmentDeliveredNotification::class);
    }

    public function test_a_shipment_reaching_a_non_delivered_status_does_not_notify(): void
    {
        Notification::fake();

        [$company, $branch, $actor] = $this->setUpTenant('DNB');
        $portalUser = $this->createUser($company, $branch);
        $customer = $this->createCustomerWithPortalUser($company, 'DNB-C-000001', $portalUser);
        $shipment = $this->createShipment($company, $branch, ['customer_id' => $customer->getKey(), 'status' => 'received']);

        $this->withTenant($company, function () use ($shipment, $actor): void {
            app(ShipmentTransitionService::class)->transitionToRole($shipment, ShipmentStatusRole::InTransit, $actor);
        });

        Notification::assertNothingSent();
    }

    public function test_issuing_an_invoice_notifies_the_customers_portal_user(): void
    {
        Notification::fake();

        [$company, $branch, $actor] = $this->setUpTenant('DNC');
        $portalUser = $this->createUser($company, $branch);
        $customer = $this->createCustomerWithPortalUser($company, 'DNC-C-000001', $portalUser);

        $invoice = $this->withTenantReturn($company, fn () => app(InvoiceService::class)->create($customer, ['currency' => 'AED'], $actor));

        $this->withTenant($company, function () use ($invoice, $actor): void {
            app(InvoiceTransitionService::class)->transition($invoice, InvoiceStatus::Issued, $actor);
        });

        Notification::assertSentTo($portalUser, InvoiceIssuedNotification::class);
    }

    private function createCustomerWithPortalUser(Company $company, string $customerNumber, User $portalUser): Customer
    {
        return $this->withTenantReturn($company, function () use ($customerNumber, $portalUser): Customer {
            $customer = Customer::query()->create([
                'customer_number' => $customerNumber,
                'type' => 'individual',
                'name' => 'Test Customer',
            ]);

            $customer->forceFill(['portal_user_id' => $portalUser->getKey()])->save();

            return $customer;
        });
    }

    /** @return array{0: Company, 1: Branch, 2: User} */
    private function setUpTenant(string $code): array
    {
        $company = $this->createCompany($code);
        $branch = $this->createBranch($company, 'DXB');
        $actor = $this->createUser($company, $branch);

        return [$company, $branch, $actor];
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
