# Architecture decisions

## Bounded areas

The application is a modular monolith. Domain areas are Identity, CRM, Shipment, Tracking, Warehouse, Freight, Customs, Delivery, Billing, Notifications, Webhooks, Reporting, and Audit. They share one database initially but communicate through application services and domain events instead of reaching through controllers.

## Request flow

`Form Request → Policy → Controller → Application/Domain Service → Transaction → Event/Outbox → Queued side effects → Resource/View`

Controllers must not contain transition rules, pricing calculations, number generation, or notification logic.

## Frontend

The staff, customer, and driver applications use Inertia.js with React and TypeScript inside the Laravel repository. Laravel remains the source of truth for authentication, route authorization, validation, policies, and data loading. React pages receive explicit view models/DTOs rather than serialized Eloquent models. Shared layouts, navigation, status badges, tables, dialogs, forms, and timelines live in `resources/js/components`; role-specific pages live under `resources/js/pages`.

Use Inertia form helpers for web workflows and reserve the versioned REST API for mobile clients and external integrations. Never duplicate authorization in React as a security boundary; frontend permission data is only for presentation.

## Tenancy

Tenant identity comes from the authenticated user or a signed integration credential, never a request-supplied `company_id`. `TenantContext` distinguishes three states: unresolved, company-scoped, and explicit platform bypass. Tenant models apply `CompanyScope`; their create hook fills and validates `company_id`.

Branch access is an additional restriction, not a substitute for company isolation. Company admins may operate all company branches; branch-scoped staff receive an allowed-branch scope checked by policy and query constraints.

Background jobs must carry the company identifier and restore tenant context before loading tenant models. Queue payloads should prefer immutable IDs and event snapshots over serialized Eloquent graphs.

## Shipment consistency boundary

The shipment aggregate owns its current state, route summary, physical summary, and financial linkage. Packages, legs, scans, tracking events, customs, and deliveries have dedicated tables but mutate shipment summary fields only through services inside database transactions.

Tracking history is append-only. A tracking write updates the shipment snapshot, adds an event, records audit metadata, and writes queued side effects using an after-commit/outbox pattern.

## State machines

Use PHP enums for stable machine values and database-managed catalogs for company-specific labels/colors. Transition maps are domain code/config with guards. Exception states do not erase the primary lifecycle; exception cases are separate records and can block selected transitions.

## Identifiers and concurrency

Human numbers use a `number_sequences` row keyed by company, branch, document type, and period. Generation locks that row inside a transaction. Database primary keys remain internal and are never accepted without policy-scoped route binding.

## Realtime and integration

Broadcast only sanitized DTOs to authorized private channels. Realtime is a projection, not the source of truth. Webhooks and notifications are queued after commit and carry idempotency keys. Carrier integrations are selected from a provider registry implementing `CarrierProviderInterface`.

## Files

Documents, delivery photos, and signatures are stored on private disks. Downloads go through policies and short-lived signed responses. Metadata is tenant-owned; storage keys are opaque and never derived solely from an original filename.

## Testing pyramid

- Unit: value objects, weight/rate formulas, transitions, signatures, assignment strategies.
- Feature: workflows, policies, tenancy, validation, transactions, private downloads.
- API: versioning, throttles, resources, object authorization, idempotency.
- Integration: MySQL locking/index behavior, Redis queues, Reverb authorization, carrier/webhook fakes.
- End-to-end: the reference Dubai → Manila → Cebu → delivery lifecycle.
