# Phase 4 checklist — routes, freight, consolidation, containers, and manifests

## Objective

Give a shipment a real multi-leg journey, let many shipments' packages be
consolidated under one carrier-level master (air or sea), track that
consolidation through containers/pallets/bags, and generate an immutable,
PDF-exportable manifest of what's loaded — all while enforcing that legs and
conveyances only move through valid states in the right order.

## Scope decisions (read before extending this phase)

- **Air/sea masters are one table, not three.** Rather than separate `Flight`
  and `Vessel` entities referenced by a `Master`, the master-bill row itself
  carries mode-specific nullable columns (`carrier_code`/`flight_number`/
  `origin_airport`/`destination_airport` for air; `shipping_line`/`vessel_name`/
  `voyage_number`/`origin_port`/`destination_port` for sea). A master bill
  already corresponds to exactly one flight or vessel run in practice, so a
  separate conveyance table would only add joins without adding a real
  distinction. `StoreMasterRequest` enforces the fields for the *other* mode
  are `prohibited`.
- **`addresses` and `NumberSequenceService` (Phase 2) are reused again.**
  Route legs reference plain origin/destination strings rather than the
  polymorphic address table (a leg's endpoints are usually airports/ports/city
  names, not full street addresses) — but master and manifest numbering both
  go through `NumberSequenceService`, including a deliberate reuse: manifest
  *versioning* uses the service with the master's own id as the "period"
  argument, giving each master an independent, lock-safe version counter
  without a new table.
- **A "route" is just its legs.** There is no separate `Route` header row —
  `RouteLeg` belongs directly to `Shipment` with a `sequence` column; the
  ordered collection of a shipment's legs *is* its route.
- **Consolidation is a foreign key, not a pivot table.** `shipment_packages`
  gained a nullable `load_unit_id`. A package is physically inside at most one
  load unit at a time, so a direct FK is both simpler and stricter than a
  many-to-many pivot would be.

## Deliverables

- [x] `route_legs`: ordered, mixed-mode legs on a shipment (`RouteLegStatus`:
      planned → loaded → departed → arrived → completed, with a cancel path).
- [x] `masters`: air/sea master bills (`MasterStatus`: open → closed → departed
      → arrived → closed_out, with a cancel path from open/closed and a
      closed → open "reopen for loading" path).
- [x] `load_units`: containers, pallets, and bags (`LoadUnitType`), each
      belonging to one master (`LoadUnitStatus`: building → loaded → in_transit
      → arrived → unloaded).
- [x] Consolidation: `PackageLoadingService::load()`/`unload()` assign/clear
      `shipment_packages.load_unit_id`, recalculating both the load unit's and
      the master's `package_count`/`weight_kg` in the same transaction.
- [x] `manifests`: an immutable (blocks `update()`/`delete()`, like `TrackingEvent`
      and `AuditLog`) JSON snapshot of a master's load at generation time, with
      Code128 already available for individual packages (Phase 3) and a new
      Dompdf-rendered PDF export for the manifest document itself.
      `dompdf/dompdf` — pure PHP, no external binary/GD dependency, same
      reasoning as Phase 3's barcode/QR libraries.

## Acceptance

- [x] **Ordered mixed-mode legs work.** A shipment can have a road leg followed
      by an air leg followed by another road leg; `RouteLegTransitionService`
      only checks each leg's own status transition plus one ordering rule — a
      leg cannot depart while an earlier leg (by `sequence`) hasn't reached a
      settled state (arrived, completed, *or cancelled* — a cancelled leg must
      not block a replanned route). `RouteLegTest` proves both the happy path
      and the blocked case, plus that a cancelled leg doesn't block later ones.
- [x] **Aggregates reconcile.** `LoadUnit.package_count`/`weight_kg` and
      `Master.package_count`/`weight_kg` are recalculated from the live
      `shipment_packages` rows inside the same transaction as every load/unload,
      exactly like Phase 3's shipment-package summary pattern.
      `LoadUnitAndManifestTest::test_loading_a_package_reconciles_load_unit_and_master_aggregates`
      asserts both levels agree after a single load action.
- [x] **Loading/departure/arrival actions enforce valid state.** A load unit
      cannot be marked `loaded` while empty; a package cannot be loaded onto a
      unit that isn't `building`, or loaded twice without unloading first; a
      master cannot depart while `open` (must be `closed` first) or with zero
      load units; a manifest cannot be generated while the master is still
      `open`. Each of these is its own test.

## Required test matrix

- [x] A user holding only `vessels.manage` cannot create or manage an air
      master, and vice versa — `MasterPolicy::create()` takes the mode as a
      second policy argument (`$user->can('create', [Master::class, $mode])`)
      specifically because Laravel's `create` ability normally only receives
      the user, not the not-yet-existing model; without that second argument a
      sea-only user could create an air master. **This was a real bug the test
      suite caught during development, not a hypothetical** — see the Phase 4
      session log in `docs/AGENT_HANDOFF.md`.
- [x] Sea-only fields are `prohibited` on an air master and vice versa.
- [x] Tenant A cannot view Tenant B's master (404 via `CompanyScope`, same
      pattern as every prior phase).
- [x] A manifest is immutable once generated (`update()`/`delete()` throw).
- [x] Regenerating a manifest for the same master produces the next version
      number, not a duplicate or an overwrite.
- [x] A generated manifest downloads as real PDF bytes (`%PDF` magic number
      asserted directly, not just a 200 status).

## Exit commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed
php artisan test
npm run typecheck
npm run build
```

All of the above pass as of this phase's completion (109 tests, 371 assertions).
Manually verified in-browser as `glx@cargoflow.test`: created an air master
(`GLX-DXB-M000001`), added a pallet load unit, loaded a shipment's package onto
it, closed the master, generated two manifest versions (v1 before loading, v2
after — confirming the version counter is genuinely per-master), and confirmed
the master summary card's package count/weight matched the load unit's exactly.

## Deliberately out of scope for this phase

- Warehouse-side scanning to physically confirm a package is on a load unit —
  that's Phase 5's `packages.scan` permission (already in the Phase 1 catalog,
  still unused).
- Customs holds blocking a master's departure — Phase 6.
- Carrier-neutral document *templates* beyond the manifest itself (e.g. a
  separate carrier-facing loading list) — the manifest snapshot has everything
  needed to build one later without new data.
