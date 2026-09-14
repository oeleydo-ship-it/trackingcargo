# Phase 7 checklist — local delivery, driver PWA, dispatch, and POD

## Objective

Get a shipment from "out for delivery" to "delivered" through a real driver
workflow: dispatch assigns a driver (manually or via a pluggable strategy),
the driver checks in on a lightweight installable PWA, records the outcome
of each delivery attempt with a captured signature (and optional photos),
and only a successful, POD-backed attempt is allowed to complete the
shipment — all while a dispatcher watches live activity and driver
positions on a board.

## Scope decisions (read before extending this phase)

- **`Driver` and `Vehicle` are top-level resources** (`/drivers`, `/vehicles`),
  the same shape as Phase 4's `Master` and Phase 5's `Warehouse` — neither is
  owned by a single shipment. **`DeliveryAssignment`/`DeliveryAttempt` are
  shipment-nested** (`/shipments/{shipment}/delivery-assignments/...`),
  matching Phase 6's `CustomsClearance` nesting, since each belongs to
  exactly one shipment. A `Driver` is a thin profile row with a required
  `user_id` FK to an existing `User` (who must separately hold the `driver`
  role via the existing Settings → Users flow from Phase 1) — this reuses
  the established "business entity + linked auth-capable User" shape from
  Phase 2's `Customer.portal_user_id`, rather than inventing new invitation
  plumbing.
- **`delivered` is structurally unreachable through the generic transition
  endpoint.** `DeliveryAssignmentTransitionMap` only allows `assigned →
  {out_for_delivery, cancelled}` and `out_for_delivery → {cancelled}` — no
  entry ever lists `delivered` as a target. The *only* code path that sets
  `DeliveryAssignmentStatus::Delivered` is `DeliveryAttemptService::record()`
  on a successful attempt, inside the same transaction as the POD document
  upload. This is what makes "completion requires POD" a structural
  guarantee rather than a validation rule someone could route around —
  proven directly by
  `DeliveryAssignmentTest::test_the_delivered_status_is_unreachable_through_the_generic_transition_endpoint`.
- **Idempotency is scoped to `DeliveryAttemptService`, not the assignment
  transition service.** `DeliveryAttempt` gets the same `idempotency_key` +
  unique `(company_id, idempotency_key)` + catch-23000-and-refetch shape as
  Phase 5's `WarehouseScanService` and Phase 6's
  `CustomsClearanceTransitionService` — necessary because a retried
  "succeeded" submission from a driver's flaky connection must not
  re-upload a signature or attempt to re-complete an already-delivered
  shipment. `DeliveryAssignmentTransitionService` (the dispatcher-facing
  "start the run" / "cancel" action) stays a plain, non-idempotent
  transition service like Phase 4's `RouteLegTransitionService` — a
  one-directional milestone with no risky duplicate-side-effect surface.
- **"Only assigned/authorized actors mutate deliveries" is a new policy
  shape**, combining two existing patterns that had never been combined
  before: branch-scoped `view`/`update` (the `ShipmentPolicy`/
  `CustomsClearancePolicy` shape) plus a specific-individual check (the one
  precedent for this in the codebase was `UserPolicy::view()`'s
  `$user->getKey() === $subject->getKey()`). `DeliveryAssignmentPolicy::execute()`
  grants a dispatcher (`deliveries.manage`) unconditional access to correct
  mistakes or cover for a driver, and grants a driver (`deliveries.execute`)
  access *only* when `$user->driver->id === $assignment->driver_id`. Proven
  by `DeliveryAssignmentTest::test_only_the_assigned_driver_can_execute_their_own_delivery`
  (a second driver in the same company is forbidden; the assigned one is
  not).
- **`documents` (Phase 6) is reused for POD as designed**, not duplicated.
  `DocumentService::upload()` was generalized from a `CustomsClearance`-only
  signature to `Model&Documentable` (a new `app/Contracts/Documentable`
  interface: `documents(): MorphMany` + `documentStoragePath(): string`);
  both `CustomsClearance` and the new `DeliveryAttempt` implement it.
  Signature and photo both land under `DocumentCategory::PodSignature`/
  `PodPhoto` (two new cases added to the existing enum), on the same
  opaque-UUID-filename `local` disk Phase 6 already set up — no new storage
  concept introduced.
- **An `AssignmentStrategy` interface was introduced for the explicit
  "assignment strategy interface" deliverable**, bound in
  `AppServiceProvider` to one concrete `RoundRobinAssignmentStrategy`
  (picks the candidate driver with the fewest currently-active
  assignments, ties broken by id). `DeliveryAssignmentService::assign()`
  does the actual candidate *filtering* (company/branch/zone, active
  status) and hands the strategy only a pre-filtered list — the strategy's
  job is purely "which of these," not "which of all drivers," keeping the
  interface swappable (a future nearest-driver or skills-based strategy
  only needs a new class + a changed binding, zero changes to the service
  or controllers).
- **Live location is "latest position," not a breadcrumb trail.** One
  `driver_locations` row per driver (`unique(driver_id)`), upserted on each
  ping — proportionate to what a dispatch board needs ("where is this
  driver right now"), not a location-history feature nobody asked for.
  Naturally idempotent (overwriting with the same or a later position is
  harmless), so — unlike `DeliveryAttempt` — it does not need an
  `idempotency_key`; the acceptance criterion's "throttled" is handled the
  same way every other rate-limited route in this codebase already is —
  `->middleware('throttle:30,1')` directly on the route, the exact pattern
  public tracking (Phase 3) and login already use, not a new
  `RateLimiter::for()` mechanism.
- **The PWA is a real, installable app shell, not full offline-first
  background sync.** `public/manifest.json` (installable, `start_url:
  /my-deliveries`) + a minimal network-first-with-cache-fallback
  `public/sw.js`, registered from `bootstrap.js`. A driver's own live
  position sharing uses `navigator.geolocation.watchPosition` posting to
  `/driver-locations` via plain `axios`, not a queued/offline-tolerant sync
  engine — building genuine offline POD-submission queueing (Workbox
  background sync, IndexedDB outbox, conflict resolution on reconnect) is a
  substantially larger feature than this phase's other nine deliverables
  combined, and wasn't asked for by the acceptance criteria (which specify
  throttling and tenant-safety for location ingestion, not offline
  tolerance). A real signature-capture `<canvas>` component
  (`SignaturePad.tsx`) was built rather than substituting a lesser "type
  your name" placeholder, since "signature ... POD" is named explicitly.

## Deliverables

- [x] `drivers`, `vehicles`, `delivery_zones` — top-level resources with
      their own Index pages.
- [x] `delivery_assignments` (`DeliveryAssignmentTransitionMap`: assigned →
      out_for_delivery/cancelled, out_for_delivery → cancelled) +
      `delivery_attempts` (immutable, idempotent, `DeliveryAttemptOutcome`:
      succeeded/failed).
- [x] Live location: `driver_locations` (latest-position upsert),
      `DriverLocationUpdated` broadcast, throttled `/driver-locations`
      ingestion route.
- [x] Failure/reschedule: a failed attempt records `failure_reason` +
      optional `reschedule_date` and leaves the assignment at
      `out_for_delivery`, ready for another attempt — no separate
      "reschedule" entity needed.
- [x] Signature/photo POD: `SignaturePad.tsx` canvas component +
      `DocumentCategory::PodSignature`/`PodPhoto`, required for a
      `succeeded` outcome, optional photos capped at 5.
- [x] POD PDF: `DeliveryAttemptPdfService`, Dompdf-rendered (same pattern as
      Phase 4's `ManifestPdfService`), embeds the signature image as a
      base64 data URI.
- [x] PWA: `manifest.json` + `sw.js` + driver-facing `Delivery/MyDeliveries.tsx`
      (mobile-first, live-location share toggle).
- [x] Dispatcher board: `Delivery/DispatchBoard.tsx` — active assignments,
      a live attempt feed (`DeliveryAttemptRecorded` on the existing
      `company.{companyId}` channel from Phase 5), and driver locations.
- [x] `AssignmentStrategy` interface + `RoundRobinAssignmentStrategy`.

## Acceptance

- [x] **Only assigned/authorized actors mutate deliveries.**
      `DeliveryAssignmentPolicy::execute()` combines branch/company scoping
      with a specific-driver check; proven by
      `test_only_the_assigned_driver_can_execute_their_own_delivery`
      (a second driver in the same company gets 403, the assigned one
      succeeds) and the existing branch/company-scope test pattern reused
      throughout `DriverAndVehicleTest`/`DeliveryAssignmentTest`.
- [x] **Completion requires POD and atomically completes the shipment.**
      `test_a_successful_attempt_requires_a_signature_and_atomically_completes_the_shipment`
      asserts a `succeeded` outcome without a `signature` file is rejected
      (`assertSessionHasErrors('signature')`), and that a valid one
      transitions the assignment to `delivered`, sets `delivered_at`,
      transitions the *shipment* to `Delivered` via the real
      `ShipmentTransitionService`, and creates exactly one POD document —
      all inside one transaction. Manually verified end-to-end in-browser:
      drew a real signature on the canvas, submitted, and confirmed both
      the assignment page ("Delivered", terminal) and a genuine
      Dompdf-rendered PDF (verified via direct HTTP response, not just a
      200 status) with the signature embedded.
- [x] **Location ingestion is throttled and tenant-safe.**
      `DriverLocationTest::test_location_pings_are_rate_limited` posts 31
      times and asserts the 31st gets HTTP 429 (`throttle:30,1`);
      `test_a_user_without_a_driver_profile_cannot_ping_a_location` proves
      the route requires both `deliveries.execute` and an actual linked
      `Driver` row — a plain authenticated user with neither cannot post a
      position at all, let alone another company's.

## Required test matrix

- [x] A shipment cannot have two open (non-cancelled/non-delivered) delivery
      assignments at once.
- [x] Retrying an attempt submission with the same `idempotency_key` creates
      exactly one `delivery_attempts` row and does not re-attempt to
      complete an already-delivered shipment.
- [x] A failed attempt does not complete the assignment and a further
      attempt can still be recorded against the same assignment.
- [x] An attempt cannot be recorded while the assignment is still `assigned`
      (not yet `out_for_delivery`).
- [x] Without a `driver_id`, the least-loaded active driver in the
      shipment's branch is auto-selected (`RoundRobinAssignmentStrategy`
      exercised end-to-end through the real assignment endpoint, not
      tested in isolation).
- [x] A vehicle's registration number is unique per company; a user can be
      linked as a driver at most once.
- [x] Tenant A cannot view or mutate Tenant B's driver (404 via
      `CompanyScope`, same pattern as every prior phase).

## Exit commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed
php artisan test
npm run typecheck
npm run build
```

All of the above pass as of this phase's completion (155 tests, 525
assertions, up from 137/446). Manually verified in-browser as
`glx@cargoflow.test`: booked a shipment, advanced it to `in_transit`,
auto-assigned it (blank driver field → the seeded demo driver was correctly
picked by `RoundRobinAssignmentStrategy`), started the run
(`out_for_delivery`, and confirmed the shipment itself moved to
`out_for_delivery` too), recorded a **failed** attempt with a reason
(assignment stayed `out_for_delivery`, ready for another try — confirmed
the failure/reschedule path doesn't dead-end a delivery), then recorded a
**succeeded** attempt with a real hand-drawn canvas signature — the
shipment and assignment both flipped to `Delivered` atomically, and the
generated POD PDF (`/attempts/{id}/pod.pdf`) rendered correctly with the
signature embedded. Also confirmed the dispatch board, drivers/vehicles
index pages, and the phase badge/nav all render correctly, and that a
driver-role user sees "My Deliveries" in place of "Local Delivery" in the
sidebar.

## A real local-environment bug found and fixed during this phase

Not an application bug, but worth recording since the fix is now part of
the dev tooling: **`HandleInertiaRequests::share()` initially queried
`$user->driver` eagerly**, which broke two categories of existing,
previously-green tests (`EmailVerificationTest`, then all of
`PublicTrackingTest`) with `LogicException: Tenant context has not been
resolved.` `HandleInertiaRequests` sits in the *global* `web` middleware
group, so it runs (a) before route-level `tenant` middleware resolves
`TenantContext` on tenant-scoped routes, and (b) on routes like public
tracking that never resolve tenant context at all (and can still carry an
authenticated session, since Laravel's test helpers don't reset auth
between `$this->actingAs()`-driven setup requests and a later anonymous
`$this->get()` in the same test). Fixed by making `isDriver` a closure
(evaluated lazily, only when Inertia actually builds the response — same
reasoning as the existing `flash` closures) *and* guarding it with
`$tenantContext->isResolved()` first, rather than assuming tenant context
is ever available at all. Caught entirely by the full regression suite,
not by any Phase 7-specific test — a reminder to always re-run the whole
suite after touching shared middleware, not just the new phase's tests.

## Not run / deliberately out of scope

- No true concurrent-connection race test for the idempotency-key
  constraint, for the same documented reason as every prior phase's reuse
  of this pattern.
- No offline-first POD submission queueing (see "Scope decisions" above) —
  the PWA is installable and app-shell-cached, not offline-tolerant for
  writes.
- No map rendering on the dispatch board — driver positions are shown as
  plain coordinates + timestamp, not plotted on a map (would need a mapping
  library/tile provider, a genuinely separate scope decision from anything
  else in this phase).
- A genuine **local-environment PHP configuration issue** was hit and
  worked around during manual verification, worth recording for the next
  agent: this Windows dev environment's `php artisan serve` process could
  not create PHP's upload temp file (`PHP Request Startup: File upload
  error - unable to create a temporary file`) for any multipart file
  upload, regardless of file size — a PHP-level `upload_tmp_dir` problem
  unrelated to any of this phase's code (confirmed by reproducing it with a
  raw `fetch()`/`FormData` call bypassing React entirely, and by the fact
  that `DeliveryAttemptTest`'s `UploadedFile::fake()`-based HTTP test
  already passed correctly under `php artisan test`, which uses a
  different PHP invocation path than the CLI built-in dev server).
  `upload_tmp_dir` is `PHP_INI_SYSTEM`-only (not settable via `.user.ini` or
  `ini_set()`), so the fix was a new `public/dev-router.php` (mirroring
  Laravel's own `vendor/laravel/framework/.../server.php` router, but with
  `chdir(__DIR__)` so it works regardless of the launcher's starting
  working directory) plus a `.claude/launch.json` change to invoke
  `php -S` directly with `-d upload_tmp_dir=<project>/storage/app/tmp -t
  public public/dev-router.php` instead of going through `artisan serve`
  (whose `ServeCommand` spawns a child process and does not forward
  arbitrary `-d` flags to it). This is local dev tooling only — the
  Composer/`.env` production configuration is untouched.
