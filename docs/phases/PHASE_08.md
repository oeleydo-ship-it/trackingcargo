# Phase 8 checklist — rates, invoicing, payments, and COD

## Objective

Turn completed operations into money: a weight-tiered rate calculator prices
a shipment, an invoice aggregates one or more shipments' charges for a
customer, payments apply against that invoice with a hard "cannot
over-apply" guarantee, a customer credit limit blocks issuing an invoice
that would breach it, and a driver's cash-on-delivery collections
reconcile against remittances handed back to the company — all money in
fixed-precision decimals, all mutating commands idempotent.

## Scope decisions (read before extending this phase)

- **`RateCard`/`RateCardTier` are top-level resources** (`/rate-cards`), the
  same shape as Phase 5's `Warehouse` and Phase 7's `Driver`/`Vehicle` —
  reusable across shipments and customers, not owned by either.
  `RateCalculatorService::calculate()` is a **pure, side-effect-free**
  function (`RateCard` + weight in, `{amount, currency}` out) — deliberately
  kept out of any service that persists, so it stays trivially unit-testable
  and is safe to call speculatively (e.g. a future quote screen) without a
  DB write.
- **`Invoice` is customer-nested, not shipment-nested**
  (`/crm/customers/{customer}/invoices/...`), unlike every prior phase's
  `{shipment}`-nested resources (`RouteLeg`, `CustomsClearance`,
  `DeliveryAssignment`). This is the first genuine one-to-many-across-
  shipments relationship in the app: one invoice's `invoice_items` can each
  point at a different `shipment_id` (nullable, for manual line items), so
  the invoice has to live under the customer, mirroring how `Customer` is
  already top-level while its shipments are not. `Invoice::create()` reuses
  Phase 2's `NumberSequenceService` for `{customer_number}-INV-{seq}`
  numbering, the same allocator every prior numbered-document phase used.
- **`paid`/`partially_paid` are structurally unreachable through the
  generic transition endpoint**, exactly mirroring Phase 7's `delivered`.
  `InvoiceTransitionMap::ALLOWED` only lists `draft → {issued, void}` and
  `issued → {void}` — no entry ever names `paid` or `partially_paid` as a
  target. The only code path that can set them is
  `PaymentService::recalculate()`, called after every payment inside the
  same transaction as the `Payment` row insert. Proven by
  `InvoiceTest::test_paid_and_partially_paid_are_not_reachable_through_the_transition_endpoint`.
- **Idempotency reuses the exact three-implementation shape** from
  `WarehouseScanService`/`CustomsClearanceTransitionService`/
  `DeliveryAttemptService`: check `idempotency_key` first (fast path), then
  `DB::transaction()`, catch a unique-constraint race (MySQL code 23000) on
  the insert and re-fetch the winner's row. Both `PaymentService::record()`
  and the new `CodRemittanceService::remit()` use it — a retried payment or
  cash-handoff submission from a flaky connection must never double-apply.
- **"Cannot over-apply" is a direct amount-vs-live-balance guard, not a
  database constraint.** `PaymentService::record()` rejects `amount >
  balance_due` (both rounded to 2dp before compare) with a `ValidationException`
  naming the exact numbers; `CodRemittanceService::remit()` does the
  identical check against `outstandingCod($driver)`. Both balances are
  always **recomputed from live child rows** inside the guard and inside
  `recalculate()` — `invoice.balance_due = total - sum(payments.amount)`,
  `outstandingCod = sum(delivery_attempts.collected_amount) -
  sum(cod_remittances.amount)` — the same "recalculate from live child
  rows" idiom `PackageLoadingService`/`WarehouseScanService`/
  `CustomerBalanceService` already established; there is no independently
  incremented running counter anywhere in the money path.
- **Credit limit is enforced only at the `issue` transition**, not at
  invoice/item creation. `Customer.credit_limit` is nullable (no limit by
  default — most customers won't have one); `InvoiceTransitionService`
  computes `CustomerBalanceService::outstandingBalance($customer) +
  $invoice->total` and rejects the transition if that would exceed the
  limit. This lets ops build up a draft invoice freely (adding/removing
  items) and only blocks the moment it becomes a real, collectible
  obligation — the same "guard the state-changing action, not every write"
  shape as Phase 7's void-if-paid check.
- **COD reuses and extends Phase 7's `DeliveryAttemptService` rather than
  building a parallel "collection" concept.** `shipments.cod_amount`
  (amount owed at delivery) and `delivery_attempts.collected_amount`/
  `collected_currency` (what the driver actually recorded at the point of a
  successful POD attempt) are the only new columns on existing tables; a
  new `cod_remittances` table is the driver-to-company handoff, structured
  identically to `payments` (idempotent, immutable, `actor_id`). This keeps
  "money collected in the field" attached to the same attempt record that
  already proves delivery happened, instead of inventing a second
  state machine that could drift out of sync with delivery status.
- **`Payment` and `CodRemittance` are immutable rows** (no `updated_at`,
  `booted()` throws on `updating`/`deleting`), the same shape Phase 3's
  `TrackingEvent` established for anything that is itself a financial or
  audit record — a payment can be reversed by a new row (future phase), never
  edited in place.
- **The financial audit trail is the existing generic `AuditService`**, not
  a new mechanism — every money-mutating action (`invoice.created`,
  `invoice-item.added`/`removed`, `invoice.status-changed`,
  `payment.recorded`, `cod-remittance.recorded`) calls
  `AuditService::record()` exactly like every prior phase's domain actions,
  satisfying the "financial audit trail" deliverable without inventing a
  parallel ledger table.

## Deliverables

- [x] `rate_cards`/`rate_card_tiers` (scoped optionally by branch/customer/
      mode), `RateCardService`/`RateCardTierService` (tier-overlap guard on
      create), pure `RateCalculatorService` (tier match + `min_charge`
      clamp).
- [x] `invoices`/`invoice_items`, `InvoiceService` (create, add
      shipment/manual item via the calculator, remove item — items
      editable only while `draft`), `InvoiceTransitionMap` + service
      (credit-limit guard on issue, paid-with-payments-cannot-void guard).
- [x] `payments` (idempotent, immutable, cannot-over-apply),
      `PaymentService::recalculate()` driving `partially_paid`/`paid`.
- [x] `customers.credit_limit` + `CustomerBalanceService` (live outstanding
      balance across `issued`/`partially_paid` invoices).
- [x] `shipments.cod_amount`, `delivery_attempts.collected_amount`/
      `collected_currency` (recorded on a successful delivery attempt),
      `cod_remittances` (idempotent, immutable) + `CodRemittanceService`
      (live `outstandingCod()` reconciliation, cannot-over-remit).
- [x] Financial audit trail via the existing `AuditService` on every
      money-mutating action.
- [x] React: `Billing/RateCards/Index.tsx`, `Billing/Invoices/Show.tsx`
      (items — from-shipment or manual — transition control, payments
      list + record form with the established per-submission
      idempotency-key pattern), `Delivery/CodRemittances/Index.tsx`
      (outstanding-by-driver + remit form + recent remittances), an
      "Invoices" section and an editable `credit_limit` field added to
      `Crm/Customers/Show.tsx`, a conditional "Collected amount" field
      added to `Delivery/Assignments/Show.tsx`'s attempt form. "Finance"
      nav now points at `/rate-cards`; phase badge bumped to "Phase 8".

## Acceptance

- [x] **Totals use decimal arithmetic.** Every money column is
      `decimal(12,2)`, every currency column `char(3)`, every model cast is
      `'decimal:2'` — no `float` anywhere in the money path. `subtotal`,
      `total`, `amount_paid`, and `balance_due` are always recomputed with
      `round(..., 2)` from live child rows, never independently
      incremented.
- [x] **Payment/COD commands are idempotent and cannot over-apply.**
      `PaymentTest::test_retrying_the_same_idempotency_key_does_not_duplicate_the_payment`
      and `CodRemittanceTest::test_retrying_the_same_idempotency_key_does_not_duplicate_the_remittance`
      each post an identical payload twice and assert exactly one row;
      `PaymentTest::test_a_payment_cannot_exceed_the_outstanding_balance`
      and `CodRemittanceTest::test_a_remittance_cannot_exceed_the_outstanding_collected_cod`
      assert the exact rejection. Manually reproduced the over-apply
      rejection in-browser with the real error copy shown inline.
- [x] **Reports reconcile.** `CodRemittanceController::index()`'s
      "outstanding by driver" figure and `CustomerBalanceService`'s
      "outstanding balance" figure are both **live queries against child
      rows**, never a cached/stored total that could drift — proven
      structurally (there is no `outstanding_balance` or
      `outstanding_cod` column anywhere to drift) and exercised by
      `CodRemittanceTest::test_a_driver_can_remit_their_own_collected_cod_and_the_outstanding_balance_drops`.

## Required test matrix

- [x] The calculator selects the correct tier and clamps up to
      `min_charge` (`RateCardAndCalculatorTest`).
- [x] Overlapping rate-card tiers are rejected at creation.
- [x] A draft invoice can receive both a shipment-derived item (via the
      calculator) and a manual item; `subtotal`/`total`/`balance_due`
      recompute correctly (`InvoiceTest`).
- [x] Line items cannot be added or removed once an invoice is issued.
- [x] Issuing an invoice that would exceed the customer's credit limit is
      rejected; the invoice stays `draft`.
- [x] An invoice with any payment applied cannot be voided.
- [x] `paid`/`partially_paid` are not reachable through the generic
      transition endpoint.
- [x] A branch-scoped user can create an invoice for a company-wide
      (`branch_id === null`) customer — regression test for the policy bug
      described below.
- [x] A partial payment moves an invoice to `partially_paid`; the payment
      that exactly clears the balance moves it to `paid` (`PaymentTest`).
- [x] A payment cannot exceed the outstanding balance; a payment cannot be
      recorded against a `draft` invoice.
- [x] A successful delivery attempt records `collected_amount`; a driver
      can remit their own collected COD and the outstanding balance drops
      by exactly that amount; a driver cannot remit on behalf of another
      driver (`CodRemittanceTest`).
- [x] A remittance cannot exceed the outstanding collected COD.

## A real authorization bug found and fixed during this phase

**Caught by manual in-browser verification, not by an automated test
written in advance** — the same category of bug Phase 4 caught with
`MasterPolicy::create()`. `InvoicePolicy::create()` checked:

```php
return $user->company_id === $customer->company_id
    && ($user->branch_id === null || $user->branch_id === $customer->branch_id);
```

— missing the third leg of the three-way OR every other branch-scoped
policy in this codebase uses (`CustomerPolicy::view()`,
`InvoicePolicy::view()` itself, `ShipmentPolicy`): `$customer->branch_id
=== null`. Without it, a **branch-scoped user could not create an invoice
for a company-wide customer**, even though that same user can *view* that
exact customer (`CustomerPolicy::view()` correctly allows it). Reproduced
live: `glx@cargoflow.test` (branch-scoped to DXB, but `company-admin` with
full permissions) got a 403 creating an invoice for a customer created
with no branch selected ("Company-wide"). Fixed by adding the missing
`$customer->branch_id === null ||` leg, and added
`InvoiceTest::test_a_branch_scoped_user_can_create_an_invoice_for_a_company_wide_customer`
as a regression test. No other Phase 8 policy uses this two-argument
`create()` shape, so this was the only place the bug could hide.

## Exit commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed
php artisan test
npm run typecheck
npm run build
```

All of the above pass as of this phase's completion (173 tests, 581
assertions, up from 172/579 immediately pre-fix — the regression test
above is the one net-new assertion set). Manually verified in-browser as
`glx@cargoflow.test`: created a customer, set a credit limit, created a
rate card with a tier, created a draft invoice, added a manual line item,
confirmed issuing was blocked at a low credit limit (invoice stayed
`draft`), raised the limit and issued successfully (items became
read-only, `paid`/`partially_paid` correctly absent from the transition
dropdown), recorded a partial payment (status → `partially_paid`),
attempted an over-limit payment (rejected with the exact balance in the
error message, state unchanged), recorded the final payment (status →
`paid`, payment form correctly disappeared), and confirmed the customer's
Invoices list reflects the final state. Also confirmed the Rate Cards page
and nav link, and the "Phase 8" badge, all render correctly.

## Not run / deliberately out of scope

- No true concurrent-connection race test for the idempotency-key
  constraint, for the same documented reason as every prior phase.
- No full in-browser shipment-booking-through-invoice walkthrough — a real
  shipment's `chargeable_weight_kg` only becomes non-zero after the
  warehouse-scan flow (Phase 5) runs, which is a long multi-step
  precondition unrelated to what this phase adds. The shipment-derived
  line-item path (calculator math, unit-price derivation) is instead
  proven by `InvoiceTest::test_a_draft_invoice_can_receive_a_shipment_line_item_and_a_manual_line_item`,
  which exercises the identical `InvoiceService::addShipmentItem()` code
  path the UI calls. Manual verification instead focused on the parts
  unique to this phase's UI: the credit-limit block, the over-apply
  rejection, and the draft-only items lock, all confirmed live.
- No full in-browser COD collection→remittance walkthrough for the same
  reason (requires the Phase 7 driver/assignment/attempt setup as a
  precondition); `CodRemittanceTest`'s five tests exercise the real
  `collected_amount` recording, the driver-self-vs-other authorization
  split, the over-remit guard, and — notably — the exact SQL join in
  `CodRemittanceService::outstandingCod()` that was flagged as unverified
  during implementation (a `Driver::assignments()` join to
  `delivery_attempts` under `CompanyScope`); it passed with no column
  ambiguity, resolving that open question.
- Refunds/reversals are not implemented — `Payment`/`CodRemittance` rows
  are immutable and one-directional by design (see "Scope decisions"); a
  future phase would add a new row type rather than mutating these.
- A separate PDF/export for invoices was not built (Phase 4/7 established
  the Dompdf pattern for manifests/POD; nothing in this phase's acceptance
  criteria named an invoice PDF, so it was left out rather than assumed).
