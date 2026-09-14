# CargoFlow implementation plan

## Purpose

This is the source of truth for phased delivery. Each phase must leave the application runnable, tested, and safe to hand to a different engineer or coding agent. A phase is complete only when its acceptance criteria pass; creating files alone does not count.

## Non-negotiable architecture

- Tenant isolation is deny-by-default through middleware, Eloquent scopes, policies, tenant-aware validation, and isolation tests.
- `company_id` is mandatory for tenant-owned data. `branch_id` is mandatory where operational ownership is branch-specific.
- Shipment state is controlled by a transition map. No controller or CRUD form may assign arbitrary statuses.
- Tracking events are append-only. Corrections use a compensating event and an audit entry.
- Transactional changes commit before queued notifications, broadcasts, documents, and webhooks are dispatched.
- Jobs declare retry/backoff/timeout behavior and use idempotency keys where duplicate execution has external effects.
- Money uses fixed precision decimals and ISO currency codes; weights and dimensions use normalized base units.
- Files use private storage and authorized temporary URLs.
- APIs are versioned under `/api/v1` and return resources, never raw models.
- The authenticated web application is an Inertia.js React SPA inside Laravel. Laravel owns routes, sessions, authorization, validation, and domain services; React owns interactive application screens.

## Delivery phases

### Phase 0 — Repository and architecture baseline (`COMPLETE`)

Deliverables: Laravel 13 scaffold pinned to PHP 8.3+, environment contract, roadmap, architecture, and handoff documentation.

Acceptance: dependency constraints are correct, secrets are not committed, and environment blockers are documented.

### Phase 1 — Identity, companies, branches, tenancy, and RBAC (`COMPLETE`)

Deliverables:

- Companies, branches, tenant-aware users, roles, permissions, and assignment pivots.
- Explicit tenant context, company scope, tenant middleware, and reusable tenant model concern.
- Explicit, auditable super-admin bypass.
- Authentication, login throttling, policies, role management, and two-factor-ready fields.
- Seed roles: Super Admin, Company Admin, Operations, Warehouse, Customer Service, Driver, Customer.

Acceptance:

- Cross-company reads, updates, relationship traversal, route binding, exports, and API access are rejected in tests.
- Tenant records cannot be created without resolved tenant context.
- A company administrator cannot grant platform permissions.
- Authentication and permission feature tests pass on MySQL 8.

### Phase 2 — Customer CRM and address book (`COMPLETE`)

Deliverables: customers, contacts, typed addresses, notes, status, search, import, customer numbering, and portal identity links.

Acceptance: number generation is concurrency-safe; address ownership/isolation tests pass; search covers name, company, phone, email, tax ID, and identification number.

### Phase 3 — Shipment core, packages, pricing weight, and public tracking (`COMPLETE`)

Deliverables:

- Shipments, parties, packages, number sequences, configurable status catalog, and controlled transition graph.
- Air/courier volumetric formulas with configurable divisors and unit normalization.
- Immutable tracking service, safe public tracking, shipment/package barcode and QR generation.

Acceptance: tracking numbers are collision-safe; invalid transitions fail; movements atomically update summary and history; public tracking exposes no private fields.

### Phase 4 — Routes, freight, consolidation, containers, and manifests (`COMPLETE`)

Deliverables: multi-leg routes, air/sea masters, flights/vessels, containers, pallets/bags, consolidations, manifests, carrier-neutral documents, and manifest PDF/export.

Acceptance: ordered mixed-mode legs work; aggregates reconcile; loading/departure/arrival actions enforce valid state.

### Phase 5 — Warehouse and scanning (`COMPLETE`)

Deliverables: warehouses, zones, locations, inventory position, receiving, sorting, loading, unloading, dispatch, idempotent scans, and realtime scan board.

Acceptance: retrying a device request is safe; every scan creates tracking/audit entries; positions reconcile by location.

### Phase 6 — Customs and document security (`COMPLETE`)

Deliverables: customs clearances and transitions, duties/taxes, inspections, private documents, and document categories.

Acceptance: customs and shipment transitions agree; downloads are authorized; hold/clearance side effects are idempotent.

### Phase 7 — Local delivery, driver PWA, dispatch, and POD (`COMPLETE`)

Deliverables: drivers, vehicles, zones, assignments, attempts, live location, failure/reschedule, signature/photo POD, POD PDF, PWA, dispatcher board, and assignment strategy interface.

Acceptance: only assigned/authorized actors mutate deliveries; completion requires POD and atomically completes shipment; location ingestion is throttled and tenant-safe.

### Phase 8 — Rates, invoicing, payments, and COD (`COMPLETE`)

Deliverables: rate cards/calculator, invoices/items, payments, credit, COD collection/remittance, and financial audit trail.

Acceptance: totals use decimal arithmetic; payment/COD commands are idempotent and cannot over-apply; reports reconcile.

### Phase 9 — Realtime, notifications, webhooks, and carrier adapters (`COMPLETE`)

Deliverables: Reverb/Echo channels, Redis/Horizon queues, notification preferences/adapters, signed retried webhooks, provider registry, and tracking polling.

Acceptance: channels prevent cross-company subscriptions; rollbacks emit no external effects; documented signature verification passes.

### Phase 10 — Dashboards, reports, search, hardening, and release (`COMPLETE`)

Deliverables: role dashboards, operations boards, global search, reports/exports, audit/failed jobs, schedulers, demo data, performance/accessibility/security/backup checks, and deployment runbook.

Acceptance: reference lifecycle passes end-to-end; critical test suites pass; security review finds no broken object authorization; staging rollback is rehearsed.

## All phases complete

Phases 0–10 are all `COMPLETE` as of this line. CargoFlow's full specified
scope — multi-tenant identity/RBAC, CRM, shipment booking and tracking,
freight consolidation, warehouse operations, customs clearance, local
delivery with POD, rating/invoicing/payments, realtime/notifications/
webhooks/carrier polling, and dashboards/reports/search/hardening — has
been implemented, tested, and reviewed. See `docs/phases/PHASE_10.md` for
the release-gate evidence (reference lifecycle test, security review
findings, rollback rehearsal) and `docs/DEPLOYMENT.md` for how to actually
run this in production. There is no Phase 11 in this plan; further work is
maintenance, not phased feature delivery — see `docs/AGENT_HANDOFF.md`'s
"First actions for the next agent" for what that looks like.

## Phase execution checklist

1. Read this file, `docs/ARCHITECTURE.md`, and `docs/AGENT_HANDOFF.md`.
2. Confirm the prior phase acceptance suite is green.
3. Add migrations in dependency order with constraints and query-driven indexes.
4. Implement domain services before controllers and UI.
5. Add policies and isolation tests beside each resource.
6. Add API/UI adapters only after domain workflows pass.
7. Run formatter, analysis, tests, and frontend build.
8. Update phase status and handoff ledger with exact results and risks.

## Dependency note

After PHP 8.3 is active, add Laravel 13-compatible Sanctum, Reverb, Horizon, Inertia Laravel, React, and maintained RBAC packages. Prefer first-party packages for infrastructure and record exact locked versions.
