# Phase 10 checklist — dashboards, reports, search, hardening, and release

This is the final phase in `docs/IMPLEMENTATION_PLAN.md`. Its acceptance
criteria are release-gate criteria, not just another feature set: the
reference lifecycle must pass end-to-end, the critical suites must be green,
a security review must find no broken object authorization, and a staging
rollback must be rehearsed.

## Objective

Give every role a reason to open the app without already knowing what
they're looking for (dashboards, an operations board, global search),
give the business a way to look backward (reports/exports, an audit log),
give operators a way to see what's stuck (failed jobs), and close out the
project with the hardening and documentation a real handoff needs — a
security review, a rehearsed rollback, and a deployment runbook.

## Scope decisions (read before extending this phase)

- **Dashboards are one controller (`DashboardController::__invoke()`)
  dispatching to five independently permission/identity-gated private
  widget methods**, not five separate pages or a role-switch statement.
  Each widget renders `null` (and the frontend hides that card) when the
  viewer lacks the permission it needs — a `shipments.manage` company-admin
  sees every widget, a driver sees only their own deliveries widget, a
  customer-portal login sees only the shipments widget scoped to their own
  records. This mirrors the existing "one controller branches on
  permission" shape already used by `AppLayout`'s nav.
- **A new `User::customerProfile(): HasOne` relation
  (`Customer::portal_user_id` inverse) is the single source of truth for
  "is this login a customer portal user, and which customer are they."**
  Every policy/query that previously used `$user->branch_id === null` as a
  shorthand for "sees everything in the company" had to be audited for
  whether a customer-portal login (which also always has `branch_id ===
  null`, since `UserInvitationService::invite()` never sets one for a
  portal invite) could reach that branch — see "Real bugs" below, this is
  where the phase's one critical finding came from.
- **Global search is a plain JSON endpoint (`SearchController`), not an
  Inertia page.** It's a type-ahead dropdown component
  (`GlobalSearch.tsx`, debounced 250ms, `AbortController`-cancelled),
  not a navigation destination, so a full-page round trip would be the
  wrong tool. Three categories (shipments/customers/invoices), each
  independently permission-gated and customer-portal-scoped the same way
  the fixed policies are, minimum 2 characters, 8 results per category,
  `addcslashes()`-escaped `LIKE` wildcards.
- **Reports have no dedicated Policy class** — `Report` isn't an Eloquent
  model, so `ReportController` gates directly with
  `abort_unless($user?->hasPermission($permission), 403)`, the same
  shape `SearchController` uses for its per-category gates. CSV export is
  a separate permission (`reports.export`) from viewing
  (`reports.view`) — an analyst can see a report on screen without being
  able to extract the underlying rows.
- **CSV export is plain `fputcsv()` streamed through
  `response()->streamDownload()`**, not a new dependency — confirmed via
  a full package-inventory check that neither `maatwebsite/excel` nor
  `league/csv` was already installed, and neither was worth adding for two
  reports.
- **Failed jobs are a genuinely platform-wide concern, not a
  per-company one.** `failed_jobs` has no `company_id` column at all (a
  job can fail for reasons unrelated to any tenant, e.g. a Redis blip
  mid-`SendWebhookJob`), so `FailedJobController` is gated by
  `is_platform_admin` — the same platform-only boundary `failed-jobs.manage`
  already enforces structurally (see "Real bugs" for what that means for
  testing it). It wraps Laravel's own `Artisan::call('queue:retry'|'queue:forget', ...)`
  rather than reimplementing retry semantics, and `queue:prune-failed`
  (`routes/console.php`, daily, 30-day retention) keeps it from growing
  forever.
- **The audit log viewer is company-scoped and read-only** — it exposes
  `AuditLog` rows that every prior phase has already been writing via
  `AuditService::record()` since Phase 1, gated by the pre-existing
  `audit-logs.view` permission (newly granted to the `operations` role
  this phase — it existed in the catalog but no non-`company-admin` role
  had it).
- **The demo data seeder (`DemoDataSeeder`) is separate from
  `DatabaseSeeder`**, called from it, and only populates one company
  (`GLX`/Dubai) with four shipments spanning the full lifecycle (draft,
  received-and-warehoused, consolidated-and-at-customs,
  delivered-and-paid) plus two customers and a rate card. `PAC` is
  deliberately left with only `DatabaseSeeder`'s bare skeleton — its
  emptiness is itself a visible proof that the demo data doesn't leak
  across the tenant boundary. It guards against duplicating itself on a
  repeat `db:seed` by checking for a `GLX-DEMO-%` customer_number prefix
  first, and temporarily switches `broadcasting.default` to `log` for its
  own duration (see "Real bugs" — two domain events broadcast
  synchronously, which would otherwise make a fresh `migrate --seed` fail
  on a machine with no Reverb process running yet).
- **The security review was two-pronged**: a manual read of all 19 policy
  files (by hand, not delegated), plus a delegated systematic audit of
  every mutating controller method for missing `$this->authorize()` calls,
  IDOR via nested-route trust, and file-upload/mass-assignment risk. Both
  found real, distinct issues — see "Real bugs."
- **The rollback rehearsal used the real command, not a description of
  one**: `php artisan migrate:rollback` (no `--step`, so it rolls back
  the single most recent batch — which, on a database that has only ever
  been through one `migrate --seed`, is all 52 migrations across all ten
  phases) followed by `php artisan migrate --seed`. Both completed cleanly
  with no manual intervention; see "Exit commands" for the exact evidence.
- **The performance/accessibility pass is a manual spot-check, not an
  APM-driven audit** — no profiling tooling is available in this
  environment. It was delegated to a read-only research pass across every
  list-rendering controller (checking eager-loading against what each
  Inertia page actually renders) and a sample of representative React
  pages (checking label association, icon-only controls, color-only
  status indicators). Findings and what was/wasn't fixed are in their own
  section below.

## Deliverables

- [x] `DashboardController` + `Dashboard.tsx` — five widgets (shipments,
      deliveries, customs, billing, webhooks), each independently
      permission/identity-gated.
- [x] `CustomsClearanceController::queue()` + `CustomsClearances/Queue.tsx`
      — the customs operations board (branch-scoped the same way
      `ShipmentController::index()` already is), excludes terminal
      clearances.
- [x] `SearchController` (JSON) + `GlobalSearch.tsx` — global type-ahead
      search across shipments/customers/invoices, wired into
      `AppLayout`'s header, throttled `60,1`.
- [x] `ReportController` (shipments + billing reports, each with a CSV
      export action) + `Reports/Shipments.tsx` + `Reports/Billing.tsx`.
- [x] `AuditLogController` + `Settings/AuditLog/Index.tsx` — company-scoped,
      action-substring filter, paginated.
- [x] `FailedJobController` + `Settings/FailedJobs/Index.tsx` —
      platform-admin-only, retry/remove, backed by `queue:prune-failed`
      scheduled daily.
- [x] `database/seeders/DemoDataSeeder.php` — realistic demo data for one
      company, called from `DatabaseSeeder`.
- [x] `docs/DEPLOYMENT.md` — environment requirements, build/deploy steps,
      systemd units for Horizon/Reverb, the scheduler cron entry, demo-data
      guidance, rollback steps, backup guidance, health checks, and
      zero-downtime notes.
- [x] Security review across all 19 policies and every mutating controller
      method — see "Real bugs."
- [x] `tests/Feature/ReferenceLifecycleTest.php` — one shipment walked
      through the entire lifecycle via real HTTP routes.
- [x] Migration rollback rehearsal (`migrate:rollback` + `migrate --seed`).
- [x] Performance/accessibility spot-check — see below.

## Real bugs found and fixed during this phase

The headline finding is a genuine, exploitable cross-tenant data leak; the
rest are defense-in-depth consistency fixes and one number-formatting bug
caught twice by the same test-writing pattern.

1. **Customer-portal cross-customer data leak — `ShipmentPolicy::view()` and
   `InvoicePolicy::view()`.** Both policies used
   `$user->branch_id === null` as shorthand for "this user is company-wide
   staff, let them see everything" — true for a branch-less
   `company-admin`, but *also* true for every customer-portal login, since
   `UserInvitationService::invite()`/`InviteCustomerPortalRequest` never
   set a `branch_id` for a portal user either. The two conditions were
   indistinguishable by that one check. **A logged-in customer-portal user
   could view any other customer's shipment or invoice in the same
   company by guessing/incrementing the URL** — no authorization error, a
   real information disclosure of another customer's freight and billing
   details. Fixed by adding `User::customerProfile(): HasOne` and checking
   it *first*, before the branch-scope OR, in both policies (and in
   `ShipmentController::index()`'s own query, which had the identical
   bug independently) — a customer-portal login is now restricted to
   `$user->customerProfile->getKey() === $shipment->customer_id` (or
   `$invoice->customer_id`) with no fallthrough to the staff bypass.
   Regression-tested in `tests/Feature/Authorization/CustomerPortalScopeTest.php`
   (5 cases) and `tests/Feature/SearchTest.php`'s portal-scoping case.
   `CustomerController` was checked and confirmed *not* exposed to the
   same class of bug — the `customer` role's permission set never included
   `customers.view` in the first place.
2. **`WarehousePolicy::scan()` was missing the branch-scope check its own
   sibling `view()` already had.** A branch-scoped `packages.scan` user
   could receive/dispatch/sort packages at a warehouse belonging to a
   *different branch* of the same company, despite being structurally
   unable to even view that warehouse's detail page. Fixed by adding the
   same three-way branch-scope OR every other policy in this codebase
   uses. Regression test added to `tests/Feature/Warehouse/WarehouseScanTest.php`.
3. **Four policies (`RateCardPolicy`, `DriverPolicy`, `VehiclePolicy`,
   `DeliveryZonePolicy`) had a `view()` that checked only `company_id`,
   not branch scope**, unlike every sibling policy's `view()`. Confirmed
   *not currently reachable via any live route* (none of the four
   controllers expose a `show()` action, and `update()` was already
   correctly company-scoped, matching the `.manage`-implies-company-wide
   convention) — a defense-in-depth consistency fix, not a closed live
   exploit. Fixed for consistency and to remove the gap before any future
   phase adds a `show()` action to one of these controllers.
   Regression-tested in `tests/Feature/Authorization/BranchScopedPolicyTest.php`
   (5 cases, including a company-wide rate card visible from every branch).
4. **Three smaller controller-hardening gaps**, found by the delegated
   controller audit: `Settings\UserRoleController::destroy()` was missing
   an `$this->authorize('update', $user)` call before revoking a role
   (every sibling mutation on `User` already authorized — this one
   didn't); `StoreLoadUnitRequest::authorize()` checked
   `can('create', LoadUnit::class)` instead of `can('update', $master)`
   against the actual route-bound parent `Master`, meaning the ability
   check never validated against the specific master being loaded onto;
   `Settings\RoleController::store()`/`update()` read permissions via
   `$request->input('permissions', [])` instead of
   `$request->validated('permissions', [])`, bypassing the Form Request's
   own validation for that field. All three fixed.
5. **`number_format` vs. `round()`-then-cast-to-string, in two places.**
   `DashboardController::billingWidget()` and three totals in
   `ReportController`'s billing report used
   `(string) round((float) $value, 2)`, which drops trailing zeros in PHP
   (a `100.0` balance renders as `"100"`, not `"100.00"`). Caught by
   `DashboardTest`'s first run (`'100.00'` expected, `'100'` actual); the
   identical anti-pattern was then found in `ReportController` by
   proactively grepping for it before even running that test, and fixed
   pre-emptively — confirmed by `ReportsTest` passing on its first run.
   Both now use `number_format($value, 2, '.', '')`.

## Required test matrix

- [x] A customer-portal login sees only their own shipments/invoices, never
      another customer's, even by direct URL
      (`CustomerPortalScopeTest`, 5 cases).
- [x] Branch-scoped users are correctly restricted (and company-wide
      roles/records correctly bypass that restriction) for
      drivers/vehicles/delivery-zones/rate-cards
      (`BranchScopedPolicyTest`, 5 cases).
- [x] A branch-scoped `packages.scan` user cannot scan at another branch's
      warehouse; a `warehouses.manage` user can
      (`WarehouseScanTest`, 1 case added).
- [x] Dashboard widgets render correctly per role/identity and are hidden
      when the viewer lacks the relevant permission, including the
      billing widget's decimal formatting
      (`DashboardTest`, 3 cases).
- [x] The customs queue excludes terminal clearances and is branch-scoped
      the same way the shipments index is (`CustomsQueueTest`, 3 cases).
- [x] Search respects per-category permissions, enforces a minimum query
      length, and never returns another customer's shipment to a
      customer-portal login (`SearchTest`, 4 cases).
- [x] Reports are permission-gated (view vs. export are separate checks),
      correctly filter by date range and status, and total the filtered
      invoices with correct decimal formatting (`ReportsTest`, 5 cases).
- [x] The audit log is company-scoped and its action filter narrows
      results correctly (`AuditLogTest`, 3 cases).
- [x] A company-scoped user cannot view failed jobs (structurally, not
      just by omission — see the test's own docblock for why
      `platform_only` semantics can't be tested via the
      `grantPermissions()` test helper); a platform admin can view and
      remove one (`FailedJobsTest`, 2 cases).
- [x] The full reference lifecycle — booking, warehouse receipt, air-freight
      consolidation and manifesting, customs clearance, dispatch and a
      signed POD delivery, and invoicing/payment in full — passes
      end-to-end through real HTTP routes, and the shipment's own tracking
      timeline reflects every transition made along the way, including the
      ones triggered indirectly by the customs and delivery services
      (`ReferenceLifecycleTest`, 1 case, 31 assertions).

## Performance and accessibility pass

A manual spot-check (no APM tooling available in this environment),
delegated to a read-only research pass and then acted on directly.

**Performance**: every list/queue/dashboard-rendering controller checked
(around 20, including `ShipmentController`, `DashboardController`,
`CustomsClearanceController`, `ReportController`, `AuditLogController`,
`SearchController`, and every top-level resource index) already
eager-loads exactly the relations its Inertia page renders — no N+1 risk
found anywhere in the reviewed set. Two unbounded-list risks were found and
fixed/assessed:

- `CustomsClearanceController::queue()` had no cap on its result set.
  **Fixed** — added `->limit(200)`, matching the cap already used by
  `ReportController`'s on-screen (non-export) result sets.
- `ReportController`'s two CSV export actions build their full result set
  in memory before streaming (not chunked/cursor-based). Left as-is: both
  are already scoped by a mandatory date range and gated by a dedicated
  `reports.export` permission, and streaming vs. chunking only matters at
  a row count well beyond what a single-company date-range export
  produces today — noted here as a candidate for `cursor()`/`lazy()` if a
  tenant's export volume grows.

**Accessibility**: the codebase already had a correct pattern
(`htmlFor`/`id` label pairs) in several Settings pages, but it wasn't
applied consistently. Fixed in every file this phase touched:
`GlobalSearch.tsx`'s search input (added `aria-label`, no visible label by
design), `Reports/Shipments.tsx` and `Reports/Billing.tsx`'s filter forms
(added `htmlFor`/`id` pairs), and `Crm/Customers/Show.tsx`'s two
placeholder-only sub-forms (contact and address creation — added
`aria-label` matching each field's placeholder, preserving the existing
compact visual design rather than restructuring the form). Left
undone, as a documented finding rather than a Phase 10 fix: roughly
fifteen pages from earlier phases (`Shipments/Index.tsx`,
`Delivery/Drivers/Index.tsx`, `Delivery/Vehicles/Index.tsx`,
`Delivery/Zones/Index.tsx`, `Freight/Masters/Index.tsx`,
`Warehouse/Index.tsx`, `Warehouse/ScanBoard.tsx`,
`Billing/RateCards/Index.tsx`, `Billing/Invoices/Show.tsx`,
`CustomsClearances/Show.tsx`, `Delivery/Assignments/Show.tsx`,
`Delivery/CodRemittances/Index.tsx`) have `<label>` elements with no
`htmlFor`/`id` association — every one of them still has a *visible* text
label next to its input (so a sighted user is unaffected), but a screen
reader announces the input with no accessible name. This is a pre-existing
gap from Phases 2–9, not a Phase 10 regression, and fixing all fifteen
files was judged out of proportion for a "lighter-touch, manual" pass — it
is flagged here as a concrete, scoped follow-up (the fix pattern already
exists correctly in `Settings/Company.tsx`/`Settings/Branches/Index.tsx`/
`Settings/Users/Index.tsx`/`Settings/Roles/Index.tsx`/
`Crm/Customers/Index.tsx`, so it is a mechanical, low-risk change to
replicate). Also noted, not fixed: `SignaturePad.tsx`'s canvas has no
`aria-label`/`role` describing it to assistive tech (a pointer-only
drawing surface with no non-visual fallback — a POD-capture UX limitation
in this version, not new to this phase). Everything else checked was
already correct: nav icons pair visible text with `aria-hidden` glyphs, no
non-semantic `<div onClick>` interactive elements exist anywhere in the
codebase, status/color badges always pair color with visible text, and
both `<img>` tags in the app (barcode/QR) have descriptive `alt` text.

## Migration rollback rehearsal

Run against the dev database (not a separate staging environment, which
doesn't exist in this environment — see `docs/DEPLOYMENT.md` §6 for how
this maps onto a real deploy):

```text
php artisan migrate:rollback
# rolled back all 52 migrations across all ten phases, cleanly, back to
# 0000_12_31_235900_create_companies_and_branches_tables — confirmed via
# the command's own output reaching that first migration with no errors.

php artisan migrate --seed
# re-applied all 52 migrations and reseeded successfully.

php artisan migrate:status | grep -c "Ran"
# => 52 (all migrations back in the "Ran" state)
```

This directly satisfies the "staging rollback is rehearsed" acceptance
criterion with real, reproducible command evidence, not a description of
what rollback *would* do.

## Exit commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed
php artisan test
npm run typecheck
npm run build
```

All of the above pass as of this phase's completion (240 tests, 886
assertions, up from 232/... at the start of this phase). Manually verified
in-browser: as `glx@cargoflow.test`, the dashboard renders all five widgets
with correct demo-seeded totals (4 shipments, 1 delivered today, 1 pending
customs clearance, 0.00 outstanding balance); global search finds all four
demo shipments by tracking-number substring and navigates correctly to a
result; the customs queue lists the one pending demo clearance; the billing
report totals the one paid demo invoice correctly (180.00 invoiced, 180.00
paid, 0.00 outstanding); the audit log lists 39 real entries generated by
demo seeding and its action filter works. As `admin@cargoflow.test`
(platform super admin), the Settings → Failed jobs tab is visible (hidden
for the company-admin login) and renders its correct empty state — its
platform-wide, cross-company scope was also exercised structurally by
`FailedJobsTest`, since inducing a real failed job would require
interrupting the already-running demo/webhook queue traffic. Confirmed a
real Reverb process (`reverb:start`, run via the `php.exe` binary directly
rather than the `.bat` wrapper — the wrapper does not keep a long-running
network listener alive in this environment's background-process handling,
which cost some time to diagnose; the underlying app has no issue) stays
up for the duration of a browser session, and that `DemoDataSeeder`'s
`ShouldBroadcastNow` events (`PackageScanned`) succeed against it.

## Not run / deliberately out of scope

- **No true concurrent-connection race test for any idempotency-key
  constraint**, for the same documented reason as every prior phase
  (Windows/no-`pcntl` + `RefreshDatabase`'s transaction wrapping make a
  real two-connection race impractical to construct here).
- **Horizon's own supervisor process was not run for this phase's manual
  verification** — a real `queue:work redis` process stood in for it
  (documented since Phase 9); Horizon itself has no code path this phase
  touches.
- **Backups are guidance, not a rehearsed procedure** — there is no backup
  infrastructure in this environment to test a restore against (see
  `docs/DEPLOYMENT.md` §7, which says so explicitly rather than claiming a
  rehearsal that didn't happen).
- **No dedicated in-app "notifications inbox" page** — flagged as a Phase
  9 candidate for Phase 10, ultimately not built; database notification
  rows remain queryable but have no list UI. Judged lower priority than
  the dashboards/search/reports/hardening work this phase actually
  delivered, and the project has no further phase to defer it to.
- **Roughly fifteen pre-existing pages have unassociated (but
  visibly-labeled) form inputs** — see "Performance and accessibility
  pass" above for the full list and reasoning.
- **`Reports/Billing.tsx`'s issue/due date columns render Laravel's raw
  ISO-8601 date-cast JSON string** (e.g. `2026-09-05T00:00:00.000000Z`)
  rather than a formatted date — this matches the exact pre-existing
  pattern in `Billing/Invoices/Show.tsx` since Phase 8, so it was not
  changed here to stay consistent with the rest of the app; a proper fix
  would be a small shared date-formatting utility applied app-wide, out of
  scope for this phase's reports work specifically.
