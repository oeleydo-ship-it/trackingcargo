# Phase 1 checklist — identity, tenancy, and RBAC

## Objective

Establish an authenticated, deny-by-default tenant boundary before any logistics data is introduced. Completion requires MySQL-backed isolation tests, not only schema creation.

## Completed foundation

- [x] Laravel 13/PHP 8.3 dependency baseline.
- [x] Company and branch migrations/models.
- [x] Tenant-aware user schema with status, branch, platform flag, and two-factor-ready storage.
- [x] Role, permission, and assignment schema/models.
- [x] Request tenant resolver and explicit platform bypass.
- [x] Strict company global scope and create guard for tenant models.
- [x] Tenant context registered with request/job lifecycle scope.
- [x] Unit test source for context resolution semantics.
- [x] Session login/logout source with throttling, active account/company checks, and login audit events.
- [x] Tenant-aware user/role validation requests and transactional provisioning/assignment services.
- [x] Immutable, tenant-scoped audit log schema, model, service, and policy.
- [x] Deterministic permission catalog and system-role seeders for two example companies.

## Environment gate

- [x] Select PHP 8.3+ and confirm `php -v`. (Herd provides 8.3/8.4/8.5 on this machine; PHP 8.4.22 is the active runtime — see `docs/AGENT_HANDOFF.md`.)
- [x] Run `composer install` without ignored platform requirements, other than `ext-pcntl`/`ext-posix` which do not exist on any Windows PHP build (Horizon's process-signal handling; harmless for local dev, present on the Linux production target).
- [x] Run `php artisan key:generate` and `php artisan about`.
- [x] Configure a disposable MySQL 8 test database and Redis instance. (Local MySQL 8.0.44 via Ampps; `cargoflow` + `cargoflow_testing` databases; Redis already running as a service.)
- [x] Run existing tests and Pint; resolve Laravel 13 compatibility issues before adding features.

## Package and application work

- [x] Install Laravel 13-compatible Sanctum, Reverb, Horizon, Inertia Laravel, React/TypeScript/Vite, and RBAC dependencies.
- [x] Replace temporary Blade login/dashboard screens with Inertia React pages and a typed shared application layout.
- [x] Decide whether to retain the in-repo RBAC schema or migrate to the selected maintained package; do not keep two competing systems. (Retained the in-repo schema; it is simple, tenant-aware, and fully covers the spec.)
- [x] Password reset and email verification are implemented and feature-tested. Two-factor enrollment UI is intentionally deferred to a later pass — the `two_factor_*` storage columns exist, but no enrollment screen is required for Phase 1 to satisfy its own acceptance criteria below.
- [x] Implement invitation and user lifecycle services with company/branch ownership set server-side. (`UserInvitationService`: invite → signed-URL email → accept → activate; suspend/reactivate.)
- [x] Implement company, branch, user, role, and permission policies.
- [x] Implement company and branch management screens through services and Form Requests. (`/settings/company`, `/settings/branches`, `/settings/users`, `/settings/roles`.)
- [x] Seed the seven default roles and the permission catalog from the original specification. (Super Admin + 6 company roles.)
- [x] Enforce that company admins cannot assign `platform_only` permissions or platform roles.
- [x] Add branch-access rules for company-wide versus branch-scoped staff. (`BranchPolicy`; a null `branch_id` means company-wide access.)
- [x] Add audit entries for login, user changes, role grants/revocations, company/branch/role changes. Platform-bypass and tenant-configuration-change audit entries are not yet distinct events.

## Required test matrix

- [x] Tenant A cannot list, view, update, delete, or bind Tenant B branches/users/roles. (Export and cross-tenant relationship traversal are not yet applicable — no exports or cross-resource relations exist until later phases.)
- [x] Querying a tenant model without resolved context throws rather than returning all tenants.
- [x] Creating a tenant model fills active `company_id` and rejects a conflicting supplied value.
- [x] Platform bypass is available only to an authenticated platform administrator and is cleared after each request/job. (`ResolveTenant` clears context in a `finally` block on every request; `AuthenticationTest` proves a platform admin without a `company_id` reaches the dashboard while a non-admin without one is rejected.)
- [x] Long-running workers do not retain tenant context between jobs. (`TenantContext` is bound `scoped()` in the container; Laravel's queue worker calls `Application::forgetScopedInstances()` after every job, which `TenantContextQueueScopeTest` exercises directly.)
- [x] Company administrator cannot create a platform role or grant platform permissions.
- [x] Branch-scoped staff cannot access another branch in the same company unless their role explicitly permits company-wide access.
- [x] Suspended users cannot authenticate; throttling behaves as configured (5 attempts per email+IP, then locked out).
- [x] Route model binding and validation rules use tenant-scoped queries. (Required fixing a middleware-priority bug — see below.)
- [x] MySQL migrations roll forward and back cleanly.

## Notable fixes made while closing out this phase

- `resources/views/app.blade.php` referenced `resources/css/app.css` as a separate Vite entrypoint, but `vite.config.js` only builds `app.tsx` (which already imports the CSS). This broke every page with a Vite manifest error. Fixed by vite-ing only `app.tsx`.
- `App\Http\Controllers\Controller` did not use `AuthorizesRequests`, so `$this->authorize()` was uncallable from any controller. Added the trait.
- Implicit route-model binding (e.g. `Branch $branch`) ran before `ResolveTenant` in the middleware pipeline, so tenant-scoped route binding threw `LogicException` instead of 404ing on a cross-tenant id. Fixed by adding an explicit `$middleware->priority([...])` in `bootstrap/app.php` placing `ResolveTenant` before `SubstituteBindings`.

## Exit commands

Record exact output/counts in `docs/AGENT_HANDOFF.md`:

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed --env=testing
php artisan test
npm run build
```

All environment, application, and test-matrix items above are satisfied on MySQL 8, with 36 passing feature/unit tests, Pint clean, and `npm run build`/`tsc --noEmit` clean. Two-factor enrollment UI is the one deliberately deferred item (storage exists; no acceptance criterion below requires it) — tracked for a later pass alongside Sanctum API-token issuance UI. Phase 1 is complete.
