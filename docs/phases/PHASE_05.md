# Phase 5 checklist — warehouse and scanning

## Objective

Give packages a physical position inside a warehouse — zones and named
locations — and let staff move them through receiving, sorting, container
loading/unloading, and dispatch via a single idempotent scan intake, with a
realtime board showing scans as they happen.

## Scope decisions (read before extending this phase)

- **Position is a denormalized FK, not a status enum.** `shipment_packages`
  gained a nullable `warehouse_location_id`, exactly mirroring Phase 4's
  `load_unit_id` FK. A package's physical state is fully described by the pair
  `(warehouse_location_id, load_unit_id)`: a location set means "on the
  warehouse floor there"; a load unit set means "on that conveyance"; both
  null means "not yet received" or "already dispatched" — which one is
  disambiguated by the `package_scans` history, not by a redundant status
  column.
- **`package_scans` is the idempotent, immutable scan log** — append-only like
  `TrackingEvent` (blocks `update()`/`delete()`), but keyed by a client-supplied
  `idempotency_key` rather than a shipment status. A retried device request
  carrying the same key is looked up first and returned as-is with zero
  side effects; the unique `(company_id, idempotency_key)` constraint plus a
  "catch 23000, re-fetch" fallback is the actual safety net under a genuine
  concurrent race, deliberately reusing the exact shape of
  `NumberSequenceService`'s own concurrency pattern rather than inventing a
  new one.
- **Load/unload scans delegate to Phase 4's `PackageLoadingService`, they
  don't duplicate it.** `WarehouseScanService::applyLoad()`/`applyUnload()`
  call the existing service for the `load_unit_id` mutation and its aggregate
  recalculation, then additionally clear/set `warehouse_location_id` and
  recalculate the vacated/filled location's own `package_count`. This is
  exactly the physical confirmation Phase 4 flagged as deliberately out of
  scope for itself ("Warehouse-side scanning to physically confirm a package
  is on a load unit — that's Phase 5's `packages.scan` permission").
- **`TrackingEvent` was not reused for scans.** Its `to_status` column is a
  required, enum-cast `ShipmentStatus` — a scan action (`sort`, `load`) isn't
  a shipment-status milestone, so forcing every scan through that table would
  either need fake status values or a nullable-enum schema change to a table
  three other phases already depend on being append-only shipment history.
  `package_scans` is the tracking/audit trail for scans; `AuditService` still
  records a generic audit-log entry for every scan too, satisfying "every
  scan creates tracking/audit entries" without touching `tracking_events`.
- **No new permissions were added to the catalog.** `packages.scan`,
  `warehouse.receive`, and `warehouse.dispatch` have existed since Phase 1,
  unused until now; `warehouses.view`/`warehouses.manage` already existed too
  and now also govern zone/location CRUD (a zone or location has no standalone
  identity worth its own permission group — same reasoning Phase 4 used for
  not giving `LoadUnit` its own permission group beyond `containers.*`).
  `WarehousePolicy::scan()` takes the scan type as a second argument exactly
  like `MasterPolicy::create()`'s mode argument: `packages.scan` is required
  for every scan type, `warehouse.receive`/`warehouse.dispatch` are
  additionally required only for those two specific types.
- **Realtime uses `ShouldBroadcastNow`, not queued `ShouldBroadcast`.**
  Reverb/Echo/a `company.{companyId}` private channel were already scaffolded
  from Phase 0/1 but unused. Nominally "Phase 9's" job, but the scan board
  acceptance criterion needs it now. Broadcasting synchronously (still fired
  only after the owning `DB::transaction()` returns, never from inside it)
  avoids depending on a queue worker — which this Windows dev environment
  cannot reliably run (`ext-pcntl`/`ext-posix` are Unix-only; see
  `docs/AGENT_HANDOFF.md`). A `PackageScanPresenter::present()` helper shapes
  the same flat DTO for both the broadcast payload and the scan board's
  initial Inertia props, so the live feed and the page's first paint never
  disagree on shape.
- **A warehouse gets a plain `city`/`country_code`, not the polymorphic
  `addresses` table.** Unlike `Customer`, a warehouse doesn't need a multi-
  address book (billing/shipping/pickup variants) — one facility, one
  location descriptor. The polymorphic table is still there if a future
  phase needs a full street address.

## Deliverables

- [x] `warehouses`, `warehouse_zones` (`WarehouseZoneType`: receiving,
      storage, staging, dispatch, customs_hold, returns), `warehouse_locations`
      (each carrying a live `package_count`).
- [x] Inventory position: `shipment_packages.warehouse_location_id`, reconciled
      by `WarehouseScanService::recalculate()` inside the same transaction as
      every position-changing scan.
- [x] Receiving, sorting, container load/unload confirmation, and dispatch —
      all five expressed as one `WarehouseScanType` enum and one
      `WarehouseScanService::scan()` entry point, not five separate services.
- [x] Idempotent scan intake: `package_scans` with a unique
      `(company_id, idempotency_key)` constraint; a retried request returns the
      original scan with no re-applied mutation.
- [x] Realtime scan board: `PackageScanned` event on the `company.{companyId}`
      private channel; `Warehouse/ScanBoard.tsx` subscribes via `window.Echo`
      and merges live scans into the initial `recentScans` list, deduping by id.

## Acceptance

- [x] **Retrying a device request is safe.**
      `WarehouseScanTest::test_retrying_the_same_idempotency_key_does_not_duplicate_the_scan_or_double_count`
      posts the identical payload (same `idempotency_key`) twice and asserts
      exactly one `package_scans` row and the location's `package_count` still
      reads `1`, not `2`.
- [x] **Every scan creates tracking/audit entries.** Every branch of
      `WarehouseScanService::scan()` writes one `package_scans` row (the
      immutable per-scan record) and one `AuditLog` row
      (`package.scan.{type}`) inside the same transaction as the position
      mutation — verified indirectly by every scan test asserting on the
      resulting `package_scans`/location state, and directly in that both
      writes happen before the transaction (and therefore the broadcast)
      commits.
- [x] **Positions reconcile by location.**
      `test_sorting_moves_a_package_between_locations_and_reconciles_both_counts`
      asserts the source location drops to `0` and the destination rises to
      `1` in the same request; `test_loading_a_scanned_package_clears_its_warehouse_location_and_reconciles_the_load_unit`
      asserts the same across the warehouse/load-unit boundary.

## Required test matrix

- [x] A package must be received before it can be sorted, loaded, or
      dispatched (four separate guard clauses in `WarehouseScanService`, one
      exercised directly by `test_a_package_must_be_received_before_it_can_be_sorted`
      and `test_dispatch_requires_the_package_to_be_at_a_warehouse_location`).
- [x] A user holding only the base `packages.scan` permission cannot receive
      or dispatch without the dedicated `warehouse.receive`/`warehouse.dispatch`
      permission — `test_a_user_without_receive_permission_cannot_receive_even_with_base_scan_permission`.
- [x] Tenant A cannot view Tenant B's warehouse (404 via `CompanyScope`, same
      pattern as every prior phase) — `WarehouseManagementTest::test_a_company_cannot_view_another_companys_warehouse`.
- [x] A warehouse code is unique per company —
      `test_a_warehouse_code_must_be_unique_within_a_company`.
- [x] Loading a scanned package reconciles both the vacated warehouse location
      and the destination load unit's aggregates in one request.

## Exit commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed
php artisan test
npm run typecheck
npm run build
```

All of the above pass as of this phase's completion (120 tests, 403
assertions, up from 109/371). Manually verified in-browser as
`glx@cargoflow.test` against the freshly seeded `DXB-WH1` warehouse (seeded
with a Receiving zone/`RCV-01` location and a Storage zone/`STO-A1` location):
booked shipment `GLX-DXB-00000001` with one 5kg package
(`GLX-DXB-00000001-01`), then on the scan board — received it into `RCV-01`
(location count went 0→1, confirmed on the warehouse page), sorted it to
`STO-A1` (source dropped to 0, destination rose to 1 in the same view),
dispatched it (location cleared), then attempted to dispatch it again and got
the exact guard message ("This package must be at a warehouse location before
it can be dispatched.") inline on the form with no new row added to the live
feed — confirming the guard rejects the invalid retry without side effects.

## Not run / deliberately out of scope

- No true concurrent-connection race test for the idempotency-key constraint,
  for the same documented reason as every prior phase's `NumberSequenceService`
  reuse (Windows/no-pcntl + `RefreshDatabase`'s transaction wrapping make a
  real two-connection race impractical to construct here); the safety claim
  rests on MySQL's unique-index semantics plus the same catch-and-refetch
  pattern already proven in Phases 2-4, not a newly observed race.
- Cross-device realtime delivery (a second physical device seeing another
  device's scan over the wire) was not exercised — this dev environment has
  no `VITE_REVERB_*` env values set, so `window.Echo` never initializes
  locally and the board falls back to a full page reload after each of a
  user's own scans (which still works — Inertia's default `post()` refetches
  `recentScans`). The `company.{companyId}` channel, its authorization, and
  the `PackageScanned` broadcast payload are all wired and unit-testable, but
  actually running two authenticated Echo clients against a live Reverb
  server (`php artisan reverb:start`) was not attempted here.
- Warehouse zone/location deactivation (`is_active` toggling via an update
  endpoint) is schema-ready but has no UI/controller action yet — only
  creation was needed to satisfy this phase's acceptance criteria.
- A printable barcode/QR label for a warehouse location (mirroring Phase 3's
  `BarcodeService` for packages) was not built; a location's `code` is scanned
  as plain text for now, same as how a device would type it.
