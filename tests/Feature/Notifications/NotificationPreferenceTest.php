<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\Company;
use App\Models\Invoice;
use App\Notifications\InvoiceIssuedNotification;
use App\Services\Notifications\NotificationPreferenceService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Opt-out by default (a type with no preference row is "on" on every
 * candidate channel), and a user's explicit opt-out is respected by
 * via() filtering — proven both at the service level and end to end
 * through a real notification's via().
 */
final class NotificationPreferenceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_channel_is_enabled_by_default_when_no_preference_row_exists(): void
    {
        $company = $this->createCompany('NPA');
        $branch = $this->createBranch($company, 'DXB');
        $user = $this->createUser($company, $branch);

        $this->withTenant($company, function () use ($user): void {
            self::assertTrue(app(NotificationPreferenceService::class)->isEnabled($user, NotificationType::InvoiceIssued, 'mail'));
        });
    }

    public function test_setting_a_preference_to_disabled_is_respected(): void
    {
        $company = $this->createCompany('NPB');
        $branch = $this->createBranch($company, 'DXB');
        $user = $this->createUser($company, $branch);

        $this->withTenant($company, function () use ($user): void {
            $service = app(NotificationPreferenceService::class);
            $service->setPreference($user, NotificationType::InvoiceIssued, 'mail', false);

            self::assertFalse($service->isEnabled($user, NotificationType::InvoiceIssued, 'mail'));
            self::assertTrue($service->isEnabled($user, NotificationType::InvoiceIssued, 'database'));
        });
    }

    public function test_the_settings_endpoint_persists_a_preference_for_the_current_user_only(): void
    {
        $company = $this->createCompany('NPC');
        $branch = $this->createBranch($company, 'DXB');
        $user = $this->createUser($company, $branch);
        $otherUser = $this->createUser($company, $branch);

        $this->actingAs($user)
            ->patch('/settings/notifications', [
                'notification_type' => NotificationType::ShipmentDelivered->value,
                'channel' => 'mail',
                'enabled' => false,
            ])
            ->assertRedirect();

        $this->withTenant($company, function () use ($user, $otherUser): void {
            $service = app(NotificationPreferenceService::class);
            self::assertFalse($service->isEnabled($user, NotificationType::ShipmentDelivered, 'mail'));
            self::assertTrue($service->isEnabled($otherUser, NotificationType::ShipmentDelivered, 'mail'));
        });
    }

    public function test_an_invalid_channel_for_a_notification_type_is_rejected(): void
    {
        $company = $this->createCompany('NPD');
        $branch = $this->createBranch($company, 'DXB');
        $user = $this->createUser($company, $branch);

        $this->actingAs($user)
            ->patch('/settings/notifications', [
                'notification_type' => NotificationType::InvoiceIssued->value,
                'channel' => 'broadcast',
                'enabled' => false,
            ])
            ->assertSessionHasErrors('channel');
    }

    public function test_an_opted_out_channel_is_excluded_from_a_real_notifications_via(): void
    {
        $company = $this->createCompany('NPE');
        $branch = $this->createBranch($company, 'DXB');
        $portalUser = $this->createUser($company, $branch);

        $this->withTenant($company, function () use ($portalUser): void {
            app(NotificationPreferenceService::class)->setPreference($portalUser, NotificationType::InvoiceIssued, 'mail', false);

            $invoice = new Invoice(['invoice_number' => 'TEST-INV-000001', 'currency' => 'AED']);
            $notification = new InvoiceIssuedNotification($invoice);

            self::assertSame(['database'], $notification->via($portalUser));
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
}
