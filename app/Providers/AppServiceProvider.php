<?php

namespace App\Providers;

use App\Contracts\AssignmentStrategy;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Delivery\RoundRobinAssignmentStrategy;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn (): TenantContext => new TenantContext);

        // Holds each company's status workflow for the life of a request, so
        // the transition screens do not re-read it per shipment.
        $this->app->scoped(ShipmentStatusRepository::class);
        $this->app->bind(AssignmentStrategy::class, RoundRobinAssignmentStrategy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user): ?bool {
            return $user->is_platform_admin ? true : null;
        });

        Horizon::auth(fn ($request): bool => (bool) $request->user()?->is_platform_admin);

        $this->applyPlatformSettings();
    }

    /**
     * Layers the platform-wide settings (Settings -> Platform) on top of the
     * .env-driven mail/Stripe config, so every company's notifications and
     * any future payment integration go through whatever a superadmin
     * configured there instead of requiring a server redeploy.
     *
     * Wrapped defensively: this runs on every request, including artisan
     * commands (config:cache, migrate before this table exists, tests with
     * no DB configured yet) where the table or the connection may not be
     * ready. A missing SMTP host or Stripe key just leaves the .env defaults
     * in place.
     */
    private function applyPlatformSettings(): void
    {
        try {
            if (! Schema::hasTable('platform_settings')) {
                return;
            }

            $settings = PlatformSetting::query()->first();
        } catch (Throwable) {
            return;
        }

        if ($settings === null) {
            return;
        }

        if ($settings->smtp_host !== null) {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $settings->smtp_host,
                'mail.mailers.smtp.port' => $settings->smtp_port,
                'mail.mailers.smtp.username' => $settings->smtp_username,
                'mail.mailers.smtp.password' => $settings->smtp_password,
                'mail.mailers.smtp.scheme' => $settings->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
                'mail.mailers.smtp.require_tls' => $settings->smtp_encryption === 'tls',
                'mail.from.address' => $settings->smtp_from_address ?? config('mail.from.address'),
                'mail.from.name' => $settings->smtp_from_name ?? config('mail.from.name'),
            ]);
        }

        if ($settings->stripe_secret_key !== null) {
            config([
                'services.stripe.key' => $settings->stripe_publishable_key,
                'services.stripe.secret' => $settings->stripe_secret_key,
                'services.stripe.webhook_secret' => $settings->stripe_webhook_secret,
            ]);
        }
    }
}
