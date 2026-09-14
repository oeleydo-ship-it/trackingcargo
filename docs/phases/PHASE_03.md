# Phase 3 checklist — shipment core, packages, pricing weight, and public tracking

## Objective

Give every company a bookable shipment aggregate — parties, packages, a controlled
status lifecycle, and an append-only tracking history — with collision-safe
human-readable numbers and a public, anonymous tracking page that leaks nothing
private.

## Deliverables

- [x] `shipments`, `shipment_parties`, `shipment_packages` tables, tenant-scoped via
      `BelongsToCompany`/`CompanyScope` exactly like every other Phase 1/2 resource.
      Parties reuse the Phase 2 `addresses` polymorphic table (`ShipmentParty::addresses()`)
      rather than a new address table.
- [x] Shipment tracking numbers via the Phase 2 `NumberSequenceService`, reused
      unmodified — `document_type = 'shipment'`, scoped per branch. Format:
      `{company code}-{branch tracking prefix}-{8-digit sequence}`, e.g.
      `GLX-DXB-00000001`. The `tracking_number` column is **globally** unique (not
      just per company) specifically so the public tracking lookup — which has no
      tenant context to disambiguate — can resolve it unambiguously.
- [x] A configurable status catalog implemented as `ShipmentStatus` (a PHP enum —
      per `docs/ARCHITECTURE.md`'s "use PHP enums for stable machine values") plus a
      hard-coded `ShipmentTransitionMap`. No controller or form can assign an
      arbitrary status; every transition goes through `ShipmentTransitionService`,
      which checks the map before writing anything.
- [x] Air/courier volumetric formulas with a configurable divisor
      (`companies.volumetric_divisor`, default 6000) and full unit normalization —
      `VolumetricWeightCalculator` accepts cm/in and kg/lb and always normalizes to
      the base units the schema stores (cm, kg), per the master plan's non-negotiable
      architecture rule.
- [x] An immutable tracking service: every status transition writes exactly one
      `tracking_events` row inside the same transaction as the shipment update.
      `TrackingEvent` blocks `update()`/`delete()` at the model level (mirrors
      `AuditLog`'s pattern from Phase 1).
- [x] Safe public tracking at `/track/{trackingNumber}` — anonymous, throttled
      (30/minute/IP), and backed by a dedicated `PublicTrackingService` that returns
      a hand-built sanitized array, never the Eloquent model.
- [x] Shipment and package barcode (Code128, SVG) and QR (SVG, encoding the public
      tracking URL) generation via `picqer/php-barcode-generator` and
      `endroid/qr-code` — both pure-PHP/SVG, no GD or ImageMagick dependency, so
      nothing new to worry about across the Windows-dev/Linux-prod split.

## Acceptance

- [x] **Tracking numbers are collision-safe.** Reuses the Phase 2
      `NumberSequenceService`, whose `SELECT ... FOR UPDATE` locking was already
      proven concurrency-safe. `ShipmentManagementTest` proves two shipments in the
      same branch get sequential numbers; the schema-level global unique constraint
      on `tracking_number` is the hard backstop.
- [x] **Invalid transitions fail.** `ShipmentTransitionMap` is the single source of
      truth; `ShipmentTransitionService` checks it before touching the database.
      `ShipmentTransitionMapTest` (unit) and `ShipmentTransitionTest` (feature — an
      invalid transition leaves the shipment and its `tracking_events` untouched)
      both cover this.
- [x] **Movements atomically update summary and history.** Every transition writes
      the shipment's new status/last-location/timestamps *and* its `tracking_events`
      row inside one `DB::transaction`. Adding/removing a package recalculates the
      shipment's `package_count`/`declared_weight_kg`/`volumetric_weight_kg`/
      `chargeable_weight_kg` inside the same transaction as the package write
      (`ShipmentPackageTest`).
- [x] **Public tracking exposes no private fields.** `PublicTrackingService`
      hand-builds its response DTO field-by-field — it cannot accidentally leak a
      new private column the way a raw model serialization would. Non-public
      (`is_public = false`) tracking events never reach the DTO at all.
      `PublicTrackingTest::test_public_tracking_never_exposes_private_fields`
      asserts the shipper/consignee names, declared value, and an internal-only
      tracking note never appear in the public response.

## Required test matrix

- [x] A shipment requires exactly one shipper and exactly one consignee.
- [x] Tenant A cannot view Tenant B's shipment (404 via `CompanyScope` on route
      binding, matching the Phase 1/2 pattern).
- [x] Branch-scoped staff cannot view a shipment from a sibling branch unless they
      hold `shipments.manage`.
- [x] Only a `draft` shipment can be edited directly; anything else must go through
      a transition.
- [x] A tracking event can never be updated or deleted, even by the user who
      created it.
- [x] `tracking.update` is a distinct permission from `shipments.manage` — a user
      with only `shipments.view` cannot transition a shipment.
- [x] A shipment must always keep at least one package (the last package on a
      shipment cannot be removed).
- [x] Unit normalization round-trips correctly: 22 lb / 20 in input produces the
      same kg/cm-based volumetric weight as if kg/cm had been entered directly.
- [x] An anonymous visitor can look up a shipment with no session and no company
      context; an unknown tracking number returns a clean "not found" response
      rather than an error.

## Exit commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed
php artisan test
npm run typecheck
npm run build
```

All of the above pass as of this phase's completion (89 tests, 322 assertions).
Manually verified in-browser: created a shipment, confirmed the 50×40×30cm/6000
divisor volumetric weight (10 kg, the textbook value), transitioned it through
Booked with a tracking event recorded, viewed the rendered Code128/QR SVGs, and
confirmed the public tracking page (no login) shows the same status anonymously
and a bogus tracking number returns "not found" instead of an error.

## Reusable building blocks this phase adds for later phases

- `addresses` (Phase 2) is now used by both `Customer` and `ShipmentParty` — Phase 4
  should attach it to whatever new addressable needs one rather than adding a table.
- `NumberSequenceService` (Phase 2) now backs both customer numbers and shipment
  tracking numbers — Phase 4/8 should reuse it for manifest/invoice numbering.
- The `{from_status, to_status}`-map pattern (`ShipmentTransitionMap` +
  `*TransitionService`) is the template for any other state machine later phases
  need (customs clearance in Phase 6, delivery attempts in Phase 7).

## Deliberately out of scope for this phase

- Multi-leg routes, consolidations, and manifests — Phase 4.
- Warehouse scanning/receiving against these packages — Phase 5 (the `packages.scan`
  permission already exists in the catalog from Phase 1 but is unused until then).
- Customs clearance state — Phase 6. `ShipmentStatus::AtCustoms` exists as a
  waypoint in the transition graph, but no customs-specific data model yet.
- Company-specific status *labels/colors* (the "configurable" half of "configurable
  status catalog") — the status *values* are the enum (non-negotiable, per
  architecture); a company-facing label/color override table was judged unnecessary
  scope for this phase and can be added later without touching the transition logic.
