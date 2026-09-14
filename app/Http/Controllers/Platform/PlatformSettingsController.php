<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SendPlatformTestEmailRequest;
use App\Http\Requests\Platform\UpdatePlatformBrandingRequest;
use App\Http\Requests\Platform\UpdatePlatformGeneralRequest;
use App\Http\Requests\Platform\UpdatePlatformSmtpRequest;
use App\Http\Requests\Platform\UpdatePlatformStripeRequest;
use App\Services\Billing\StripeGatewayService;
use App\Services\Platform\PlatformSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class PlatformSettingsController extends Controller
{
    public function show(Request $request, PlatformSettingService $settings, StripeGatewayService $gateway): Response
    {
        abort_unless((bool) $request->user()?->hasPermission('system-configuration.manage'), 403);

        $current = $settings->current();
        $subscriptions = [];
        if ($current->stripe_secret_key !== null && $current->stripe_secret_key !== '') {
            try {
                $subscriptions = $gateway->listSubscriptions();
            } catch (ValidationException) {
                $subscriptions = [];
            }
        }

        return Inertia::render('Settings/Platform/Index', [
            'settings' => [
                'site_name' => $current->site_name,
                'support_email' => $current->support_email,
                'default_timezone' => $current->default_timezone,
                'default_currency' => $current->default_currency,
                'logo_url' => $current->logo_path !== null ? Storage::disk('public')->url($current->logo_path) : null,
                'favicon_url' => $current->favicon_path !== null ? Storage::disk('public')->url($current->favicon_path) : null,
                'smtp_host' => $current->smtp_host,
                'smtp_port' => $current->smtp_port,
                'smtp_username' => $current->smtp_username,
                'smtp_has_password' => $current->smtp_password !== null,
                'smtp_encryption' => $current->smtp_encryption,
                'smtp_from_address' => $current->smtp_from_address,
                'smtp_from_name' => $current->smtp_from_name,
                'stripe_publishable_key' => $current->stripe_publishable_key,
                'stripe_has_secret_key' => $current->stripe_secret_key !== null,
                'stripe_has_webhook_secret' => $current->stripe_webhook_secret !== null,
                'stripe_subscriptions' => $subscriptions,
            ],
        ]);
    }

    public function updateGeneral(UpdatePlatformGeneralRequest $request, PlatformSettingService $settings): RedirectResponse
    {
        $settings->updateGeneral($request->validated(), $request->user());

        return back()->with('success', 'General settings updated.');
    }

    public function updateBranding(UpdatePlatformBrandingRequest $request, PlatformSettingService $settings): RedirectResponse
    {
        $settings->updateBranding([
            'logo' => $request->file('logo'),
            'remove_logo' => $request->boolean('remove_logo'),
            'favicon' => $request->file('favicon'),
            'remove_favicon' => $request->boolean('remove_favicon'),
        ], $request->user());

        return back()->with('success', 'Branding updated.');
    }

    public function updateSmtp(UpdatePlatformSmtpRequest $request, PlatformSettingService $settings): RedirectResponse
    {
        $settings->updateSmtp($request->validated(), $request->user());

        return back()->with('success', 'SMTP settings updated.');
    }

    public function sendTestEmail(SendPlatformTestEmailRequest $request, PlatformSettingService $settings): RedirectResponse
    {
        try {
            $settings->sendTestEmail((string) $request->validated('to'), $request->user());
        } catch (TransportExceptionInterface $exception) {
            return back()->with('error', 'Could not send the test email. Check the SMTP host, port, encryption and credentials.');
        }

        return back()->with('success', "Test email sent to {$request->validated('to')}.");
    }

    public function updateStripe(UpdatePlatformStripeRequest $request, PlatformSettingService $settings): RedirectResponse
    {
        $settings->updateStripe($request->validated(), $request->user());

        return back()->with('success', 'Payment gateway settings updated.');
    }
}
