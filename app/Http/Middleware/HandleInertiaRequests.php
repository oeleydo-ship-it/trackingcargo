<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;
use Throwable;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);

        return [
            ...parent::share($request),
            // A closure: shared on every Inertia response site-wide (this
            // middleware sits in the global `web` group, so it also runs on
            // the login page and public tracking), but only actually queried
            // when a page renders — see Brand.tsx, the one place all three
            // surfaces read it from.
            'branding' => fn (): array => $this->branding(),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'isPlatformAdmin' => $user->is_platform_admin,
                    // Lazy AND tenant-guarded: HandleInertiaRequests sits in
                    // the global `web` middleware group, so it also runs on
                    // routes that never resolve TenantContext at all (e.g.
                    // public tracking, reached here by an already-logged-in
                    // staff session) as well as before route-level `tenant`
                    // middleware resolves it elsewhere. Driver is tenant-
                    // scoped (BelongsToCompany), so this stays a closure
                    // (evaluated only when Inertia builds the response, same
                    // as the `flash` closures below) and checks isResolved()
                    // first rather than assuming context is ever available.
                    'isDriver' => fn (): bool => $tenantContext->isResolved() && $user->driver !== null,
                    'company' => $user->company === null ? null : [
                        'id' => $user->company->getKey(),
                        'name' => $user->company->name,
                    ],
                ],
            ],
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Defaults to the stock CargoFlow look rather than 500ing the entire
     * app (this closure runs on every page, including /login) — the same
     * defensive gap AppServiceProvider::applyPlatformSettings() already
     * guards against, for the same reason: an install whose migrations
     * haven't run yet must still be able to serve a page.
     *
     * @return array{siteName: string, logoUrl: ?string, faviconUrl: ?string}
     */
    private function branding(): array
    {
        try {
            if (! Schema::hasTable('platform_settings')) {
                return ['siteName' => 'CargoFlow', 'logoUrl' => null, 'faviconUrl' => null];
            }

            $settings = PlatformSetting::current();
        } catch (Throwable) {
            return ['siteName' => 'CargoFlow', 'logoUrl' => null, 'faviconUrl' => null];
        }

        return [
            'siteName' => $settings->site_name,
            'logoUrl' => $settings->logo_path !== null ? Storage::disk('public')->url($settings->logo_path) : null,
            'faviconUrl' => $settings->favicon_path !== null ? Storage::disk('public')->url($settings->favicon_path) : null,
        ];
    }
}
