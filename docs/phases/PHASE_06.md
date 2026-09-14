# Phase 6 checklist — customs and document security

## Objective

Give a shipment a customs clearance lifecycle (submit, review, clear/hold/
reject) that stays in lockstep with the shipment's own status machine, let
staff record duties/taxes and inspections against a clearance, and let them
attach private documents to it with authorized-only downloads — all while
making the clearance's own hold/clear/reject actions safe to retry.

## Scope decisions (read before extending this phase)

- **A clearance's own lifecycle is a second, separate state machine from
  `ShipmentStatus`, not folded into it.** `CustomsClearanceStatus` (pending → under_review → cleared/held/
  rejected) is its own enum with its own `CustomsClearanceTransitionMap`,
  exactly mirroring `RouteLegStatus`/`RouteLegTransitionMap`. The two
  machines are wired together at exactly two points, both inside
  `CustomsClearanceService`/`CustomsClearanceTransitionService`, never in a
  controller: opening a clearance drives the shipment to `at_customs`
  (rejecting the open if the shipment isn't allowed there right now), and
  clearing/rejecting a clearance drives the shipment onward (to
  `in_transit`/`out_for_delivery`, or to `exception`). This is what "customs
  and shipment transitions agree" means concretely — it's not a validation
  rule bolted on after the fact, it's the same `ShipmentTransitionService`
  every other phase already uses, called from inside the clearance service.
- **`TrackingEvent` was not reused for the clearance's own sub-states.** Its
  `to_status` column is a required, enum-cast `ShipmentStatus` — `held` or
  `under_review` aren't shipment statuses. A new `customs_clearance_events`
  table is the immutable log for the clearance's own transitions (same
  append-only shape as `TrackingEvent`/`PackageScan`); `TrackingEvent` rows
  still get created, but only at the two integration points above, via the
  real `ShipmentTransitionService` call, with real `ShipmentStatus` values.
- **Idempotency reuses Phase 5's exact shape, applied to clearance
  transitions specifically.** `CustomsClearanceTransitionService::transition()`
  is a near-verbatim copy of `WarehouseScanService::scan()`'s idempotency
  handling: check `customs_clearance_events` for the `idempotency_key` first
  (fast path), then a unique `(company_id, idempotency_key)` DB constraint
  with a catch-23000-and-refetch fallback under a genuine race. This matters
  specifically here because a clear/reject transition *also* advances the
  shipment — without idempotency, a retried "clear" request would hit
  `ShipmentTransitionMap`'s guard a second time and fail, even though the
  first request already succeeded. Duty/inspection actions did **not** get
  their own `idempotency_key`: marking an already-paid duty paid again is a
  boolean no-op by construction, and completing an already-completed
  inspection is rejected by a plain status guard — neither risks a
  duplicate side effect the way a status transition does, so a second
  idempotency mechanism would be unused complexity.
- **No new permissions were added to the catalog.** `customs.view`/
  `customs.manage` have existed since Phase 1, unused until now. Duties,
  inspections, and documents are all sub-resources of a clearance and
  authorize against the clearance's own `update`/`view` ability — the same
  "no separate permission for a sub-resource" precedent Phase 3 set for
  `ShipmentPackage` (no `packages.manage`) and Phase 5 set for zones/
  locations (reuse `warehouses.*`). `CustomsClearancePolicy::create()` takes
  the target `Shipment` as a second argument, the same two-argument shape
  `MasterPolicy::create()` established in Phase 4 for a not-yet-existing
  model.
- **`documents` is a genuinely polymorphic table, not a customs-only one** —
  deliberate foresight matching the Phase 2 `addresses` table story.
  `docs/ARCHITECTURE.md` groups "documents, delivery photos, and signatures"
  as one concept, and Phase 7's driver POD explicitly needs photo/signature
  storage; building `documents` polymorphic now means Phase 7 can reuse it
  with a new `documentable_type` and no schema change. This phase's
  `DocumentService` is still typed narrowly to `CustomsClearance` (not a
  generic `Model`) — genericizing the *service* ahead of a second real
  caller would be speculative; the *table* is the only place foresight was
  spent.
- **Documents land on the existing `local` disk**
  (`storage_path('app/private')`, already outside
  the public web root and never symlinked) rather than a new disk
  definition — `config/filesystems.php` already had everything needed.
  Every stored filename is an opaque UUID (`Str::uuid()`), never derived
  from the upload's original name, satisfying the architecture guardrail on
  storage keys directly; `original_filename` is kept as a separate column
  purely for display and the `Content-Disposition` filename on download.
- **Money follows the existing convention exactly**: `decimal(12,2)` +
  `char(3)` currency, the same shape as `shipments.declared_value`/
  `currency` — no new money representation invented for duties/taxes.

## Deliverables

- [x] `customs_clearances` + `customs_clearance_events` (immutable,
      idempotent transition log) + `CustomsClearanceTransitionMap`
      (pending → under_review → cleared/held/rejected, held → under_review/
      rejected).
- [x] Duties/taxes: `customs_duties` (`DutyType`: duty/tax/fee), assess +
      mark-paid (idempotent by construction).
- [x] Inspections: `customs_inspections` (`InspectionType`: physical/xray/
      documentary/canine/other), schedule + complete (guarded against
      completing twice).
- [x] Private documents: polymorphic `documents` table (`DocumentCategory`:
      customs_declaration/commercial_invoice/packing_list/
      certificate_of_origin/import_permit/other), stored on the `local` disk
      under an opaque UUID filename, policy-gated upload/download/delete.

## Acceptance

- [x] **Customs and shipment transitions agree.** Opening a clearance calls
      `ShipmentTransitionService::transition($shipment, AtCustoms, ...)` and
      fails the whole request (`assertSessionHasErrors('status')`) if the
      shipment can't go there right now; clearing/rejecting a clearance calls
      the same service to advance/exception the shipment. Proven by
      `CustomsClearanceTest::test_opening_a_clearance_transitions_the_shipment_to_at_customs`,
      `test_a_clearance_cannot_be_opened_when_the_shipment_status_does_not_allow_at_customs`,
      `test_clearing_a_clearance_advances_the_shipment_out_of_at_customs`, and
      `test_rejecting_a_clearance_sends_the_shipment_to_exception`. Manually
      verified end-to-end: a shipment walked Draft→Booked→Received→
      InTransit→(open clearance)→AtCustoms→(clear, chose "out for delivery")→
      OutForDelivery, with every hop appearing in the real tracking timeline.
- [x] **Downloads are authorized.** `DocumentController::download()`
      authorizes against the owning `CustomsClearance` (`can('view', $clearance)`)
      before touching the disk, and the ownership chain
      (shipment→clearance→document) is checked explicitly, not inferred.
      `DocumentTest::test_a_user_from_another_company_cannot_download_the_document`
      proves a cross-tenant download 404s.
- [x] **Hold/clearance side effects are idempotent.**
      `CustomsClearanceTest::test_retrying_a_transition_with_the_same_idempotency_key_does_not_duplicate_the_event_or_reapply_the_shipment_transition`
      posts the identical transition payload twice and asserts exactly one
      `customs_clearance_events` row for that key — critically, the second
      request does *not* attempt (and therefore does not fail on) the
      shipment-transition side effect a second time.

## Required test matrix

- [x] A shipment cannot have two open (non-terminal) clearances at once.
- [x] Holding a clearance requires a `reason`; rejecting does too
      (`required_if:status,held,rejected`).
- [x] An out-of-order transition (`pending` → `cleared`, skipping
      `under_review`) is rejected by `CustomsClearanceTransitionMap`.
- [x] A user with `customs.view` but not `customs.manage` cannot open a
      clearance (`assertForbidden`).
- [x] Tenant A cannot view Tenant B's clearance (404 via `CompanyScope`).
- [x] Marking an already-paid duty paid again changes nothing (`paid_at`
      unchanged across both requests).
- [x] Completing an already-completed inspection is rejected
      (`assertSessionHasErrors('status')`).
- [x] An uploaded document's stored filename is never the original filename
      (`assertNotSame('invoice.pdf', basename($document->path))`), and
      deleting it removes the file from disk (`Storage::assertMissing`).

## Exit commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed
php artisan test
npm run typecheck
npm run build
```

All of the above pass as of this phase's completion (137 tests, 446
assertions, up from 120/403). Manually verified in-browser as
`glx@cargoflow.test`: booked shipment `GLX-DXB-00000001`, advanced it to
`in_transit`, opened a customs clearance (shipment auto-moved to
`at_customs`), transitioned the clearance to `under_review`, assessed a
150.50 AED import duty and marked it paid, cleared the clearance choosing
"out for delivery" as the shipment's next status, and confirmed both the
clearance page (now terminal, "Cleared") and the shipment page (now
"Out For Delivery", with `At Customs` and `Out For Delivery` both present in
the real tracking timeline) agree.

## Not run / deliberately out of scope

- No true concurrent-connection race test for the idempotency-key
  constraint, for the same documented reason as every prior phase's reuse of
  this pattern (Windows/no-pcntl + `RefreshDatabase`'s transaction wrapping).
- Document upload was not exercised through the actual browser file picker
  (browser-automation file-input interaction is fragile in this
  environment, same caveat noted for Phase 4's load-unit packing); the
  upload/download/delete/cross-tenant-authorization path is instead covered
  by three passing HTTP-level feature tests using `UploadedFile::fake()`
  and `Storage::fake('local')`, which exercise the real controller and
  service code, not a mock.
- No top-level "Customs" dashboard/worklist page — clearances are always
  reached from their owning shipment, matching how route legs work; the
  sidebar's "Customs" link is left unwired (`#`) for the same reason
  "Local Delivery" and "Finance" still are. A cross-shipment customs
  worklist is a natural fit for Phase 10's reporting/dashboards work, not
  this phase's scope.
- Signed, time-limited download links (vs. the current authenticated+
  policy-gated download, matching Phase 4's unsigned Manifest PDF
  precedent) were not built — nothing in this phase is customer-facing yet,
  so there was no link to share outside an authenticated staff session.
