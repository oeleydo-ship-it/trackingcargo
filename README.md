# CargoFlow

CargoFlow is a multi-tenant cargo tracking, freight forwarding, warehouse, customs, billing, and last-mile delivery platform built on Laravel 13.

The system is being delivered in independently verifiable phases. The authoritative roadmap is [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md), and the resumable agent brief is [docs/AGENT_HANDOFF.md](docs/AGENT_HANDOFF.md).

## Current status

Phases 0–10 are complete. Identity/tenancy/RBAC, customer CRM, shipment core, freight consolidation, warehouse operations, customs, delivery, billing, webhooks/realtime, search/dashboards, security hardening, and deployment handoff are implemented, documented, and wired through the Inertia/React frontend. For current handoff context, see `docs/AGENT_HANDOFF.md` and `docs/CONTINUATION_PLAN.md`.

The system PHP (via `php`/`where php`) is 8.2, which is below this project's 8.3+ requirement. Herd (`~/.config/herd/bin/php83`, `php84`, `php85`) provides compatible runtimes on this machine — Composer scripts and Artisan currently run against PHP 8.4. `laravel/horizon` requires `ext-pcntl`/`ext-posix`, which do not exist on any Windows PHP build; `composer install` needs `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` locally (both extensions are present on the Linux production target, so this does not affect deployed behavior).

## Target stack

- PHP 8.3+
- Laravel 13
- MySQL 8+
- Redis, Horizon, queues, and scheduler
- Reverb and Echo
- Inertia.js, React, TypeScript, Vite, and Tailwind CSS
- Sanctum REST API under `/api/v1`

## Local setup

1. Install or select PHP 8.3 or newer.
2. Copy `.env.example` to `.env` and set MySQL and Redis credentials.
3. Run `composer install`, `php artisan key:generate`, `npm install`, and `npm run build`.
4. Run `php artisan migrate --seed`.
5. Run `composer test`.

Do not run the application with SQLite beyond isolated unit tests: production behavior relies on MySQL 8 constraints, indexes, locking, and JSON support.

## Development rules

- Every tenant-owned record must use the tenant scope and include `company_id`; branch-owned records also include `branch_id` where applicable.
- Controllers validate, authorize, call an application/domain service, and transform the response.
- Shipment state changes go through the transition service; direct status mutation is forbidden.
- Every physical movement creates an immutable tracking event.
- External side effects are queued and idempotent.
- New phases must satisfy the acceptance checks in the implementation plan before their status is changed.
