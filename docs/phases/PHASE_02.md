# Phase 2 checklist — customer CRM and address book

## Objective

Give every company a searchable customer book — individuals and businesses, their
contacts, typed addresses, and internal notes — with concurrency-safe human-readable
numbering and an optional self-service portal login, all inside the Phase 1 tenant
boundary.

## Deliverables

- [x] `number_sequences`: a reusable, atomic (company, branch, document type, period)
      counter for every future phase that needs human-readable document numbers
      (customers now; invoices/shipments later reuse the same service).
- [x] `customers`, `customer_contacts`, `customer_notes` tables, tenant-scoped via
      `BelongsToCompany`/`CompanyScope` exactly like Phase 1 resources.
- [x] `addresses`: a polymorphic, reusable typed-address table (`billing`, `shipping`,
      `pickup`, `delivery`, `other`) attached to customers now, designed to be reused
      by shipment parties in Phase 3 rather than rebuilt.
- [x] Customer status (`active`, `inactive`, `blocked`).
- [x] Search across name, company name, phone, email, tax ID, and identification
      number (`Customer::scopeSearch`).
- [x] CSV import with per-row validation, partial success, and a per-row error report.
- [x] Customer numbering: `{company code}-C-{6-digit sequence}`, e.g. `GLX-C-000001`.
- [x] Portal identity links: a customer can be linked to exactly one `User` (via
      `customers.portal_user_id`), invited through the existing Phase 1 invitation
      flow and auto-assigned the `customer` role.
- [x] Settings-style CRUD UI at `/crm/customers` (list, search, create, detail,
      contacts, addresses, notes, portal invite/unlink) built the same way as the
      Phase 1 Settings pages.

## Acceptance

- [x] **Number generation is concurrency-safe.** `NumberSequenceService::next()` uses
      `SELECT ... FOR UPDATE` inside a transaction: the row lock is acquired
      immediately and held until commit, so two concurrent callers allocating the
      same scope serialize instead of racing. The first allocation for a new scope
      has no row to lock yet, so it inserts one and falls back to the lock-and-increment
      path if a concurrent request created that row first (duplicate-key retry).
      `NumberSequenceServiceTest` proves sequential, gapless allocation and independent
      counters per document type, per company, and per branch.

      Caveat: this environment (Windows, no `pcntl`, `RefreshDatabase`'s per-test
      transaction wrapping) cannot practically drive two real concurrent MySQL
      connections against the same uncommitted test row, so true concurrent-load
      locking was not exercised end-to-end here — the guarantee rests on MySQL's
      well-established `SELECT ... FOR UPDATE` semantics rather than an observed race.
- [x] **Address ownership/isolation tests pass.** An address (or contact, or note)
      cannot be created under, updated on, or deleted from a customer belonging to
      another company or a sibling customer in the same company — enforced by policy
      checks plus an explicit `addressable_id`/`customer_id` ownership check in each
      child controller (`CustomerAddressTest`, `CustomerContactTest`, `CustomerNoteTest`).
- [x] **Search covers name, company, phone, email, tax ID, and identification
      number.** `CustomerManagementTest::test_search_matches_name_company_phone_email_tax_id_and_identification_number`
      creates one customer per field and asserts each search term resolves to exactly
      that customer.

## Required test matrix

- [x] Tenant A cannot list, view, update, or delete Tenant B's customers, contacts,
      addresses, or notes.
- [x] A user without `customers.manage` cannot create, import, or invite a portal
      user for a customer.
- [x] A customer cannot be linked to a second portal account.
- [x] Setting a new default address for one type does not affect the default address
      of a different type on the same customer.
- [x] CSV import applies valid rows and reports invalid ones without rolling back the
      valid rows (partial success, not all-or-nothing).
- [x] Customer numbers are unique per company and allocate sequentially.

## Exit commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed
php artisan test
npm run typecheck
npm run build
```

All of the above pass as of this phase's completion (61 tests, 231 assertions).

## Deliberately out of scope for this phase

- Customer credit terms/limits and any billing linkage — that is Phase 8 (rates,
  invoicing, payments).
- Shipment-facing party/address selection — Phase 3 reuses the `addresses` table
  built here rather than duplicating it.
- Customer-facing portal *screens* (the portal user can log in and reach the
  `customer`-role dashboard from Phase 1, but no customer-specific portal UI exists
  yet — there is nothing customer-scoped to show until Phase 3 ships shipments).
