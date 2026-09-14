# CargoFlow continuation plan (for next agent handoff)

> Current handoff: read CURRENT_SYSTEM_STATUS.md first. The local database is now SQLite. The 2FA, API token/session controls and inbox backlog below was implemented in the missing-feature completion pass; see ACCOUNT_AND_INBOX.md and API_CONSUMER_GUIDE.md. Do not reset or reseed the local database.

## What is already complete in this workspace

- Laravel 13 scaffold is in place with PHP 8.3+ constraints.
- Inertia.js + React + TypeScript + Vite + Tailwind are wired as the web frontend shell.
- Tenant isolation, company/branch RBAC, invitation flow, policies, audit logging, and API scaffolding are implemented.
- Reverb/Horizon/Redis infrastructure and webhooks/notifications/cross-system broadcast wiring are configured.
- Phases 0–10 are marked complete in `docs/IMPLEMENTATION_PLAN.md`, with full release-gate evidence captured in `docs/AGENT_HANDOFF.md` and `docs/phases/PHASE_10.md`.
- `/api/v1/login`, `/api/v1/me`, `/api/v1/logout` now exist in `routes/api.php` and are protected by Sanctum with tenant-aware context.

## Current environment notes

- Primary command runner in this machine still reports PHP 8.2 in plain `php -v`, but the Herd PHP 8.4 binary is available and should be used for Laravel runtime commands.
- Horizon and queue supervisor behavior require `pcntl`/`posix` on Linux production; Windows is for app-level development only.
- Redis is expected by queue/reverb/webhook flow.

## If you continue from here

1. Start MySQL and Redis before `php artisan` operations.
2. Run the phase exit checks from `docs/AGENT_HANDOFF.md` with a PHP 8.3+ binary.
3. Prioritize 2FA enrollment UI and external API consumer docs as the remaining meaningful backlog (core platform is already scaffolded end-to-end).
4. Keep cross-tenant and branch-scope checks intact: never bypass `ResolveTenant`, `CompanyScope`, or existing policies when adding endpoints.

## Files touched in the latest continuation pass

- `app/Http/Controllers/Api/V1/AuthTokenController.php`
- `app/Http/Requests/Api/V1/ApiLoginRequest.php`
- `app/Http/Resources/Api/V1/UserResource.php`
- `config/sanctum.php`
- `routes/api.php`
- `bootstrap/app.php`
- `docs/AGENT_HANDOFF.md` (historical entries remain; this pass adds the current handoff file below)

## Suggested next coding phase

- Build a `My Profile / API Tokens` screen in React so users can see active sessions and self-revoke tokens.
- Add request-level tests for `/api/v1/login`, `/api/v1/me`, and `/api/v1/logout`.
