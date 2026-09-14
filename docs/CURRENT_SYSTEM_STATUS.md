# Current system status

## Superadmin console — 2026-09-11

Verification: 377 tests passed with 1,722 assertions on isolated in-memory SQLite. TypeScript checking, PHP formatting and the production React build passed. Provider calls were faked in tests; no live SMTP email or Stripe request was made.

New `/superadmin` React console provides global workspace/user directories, workspace provisioning with an administrator invitation, workspace status controls, company-bound user branch/role editing, suspension/reactivation, session/token revocation, invitation resend and password-reset delivery. Company accounts cannot access these endpoints. The existing platform-admin roster, SMTP, branding, audit and failed-job pages are linked from the console. SMTP scheme mapping and secret handling were hardened; Stripe credential checks and a read-only connection test were added.

See SUPERADMIN_CONSOLE.md for the implementation map, operating instructions and boundaries. No migration or reset is required. Online checkout, inbound payment settlement/refunds, workspace subscriptions and multiple merchant accounts are not implemented by this administration feature. They require a separate payment-flow design, not merely saved credentials.

## Batch tenant-context repair — 2026-09-11

Verification: 368 tests passed with 1,635 assertions on isolated in-memory SQLite; PHP formatting passed. This repair changes backend code only, with no database migration or frontend build required.

The reported GET /batches/1 failure occurred for a platform administrator without a selected acting company. Batch transition lookups now explicitly use the authorized batch's company ID. Batch members, assignable shipments and status labels are also filtered to that company, including when the platform administrator is in its intentionally cross-company oversight mode. Status codes shared by multiple companies cannot supply another company's label or workflow on the batch detail screen.

Company users still resolve their company from their authenticated account, not request fields or the platform acting-company session key. Route binding runs after tenant resolution and rejects foreign-company batch IDs with 404. Platform administrators acting as one company are also restricted to that selected company. No global scope, authorization rule or tenant-context fail-closed guard was disabled. No local records were reset or reassigned.

Regression coverage includes the platform-admin detail error, same-code/different-company workflow labels, sequential requests without context leakage, selected-company platform boundaries, company-only batch/branch listings, guessed foreign IDs, and forged company/branch input. Existing cross-company tests also cover shipments, CRM, documents, settings, freight, warehouse, webhooks and realtime channels. These checks do not constitute a complete independent security audit; platform administrators retain explicitly privileged cross-company oversight.

## Local repairs and verification — 2026-09-10

The local app uses PHP 8.4 and SQLite (`database/database.sqlite`), with Laravel at http://127.0.0.1:8001 and Vite configured for port 5176. Earlier MySQL instructions in historical handoffs do not describe the current local database. Do not run migrate:fresh or reseed this database: it contains user changes.

The GLX administrator and driver were assigned to deleted branch 1 (Dubai Head Office). That branch was restored, with a branch.restored audit entry. The newer Dubai branch remains present. Before removing the original branch, intentionally reassign its users and review its operational records.

Login now rejects inactive, removed, or foreign-company branch assignments with an inline message. Existing web sessions with invalid branch access are signed out and redirected to login. API access stays forbidden and new tokens are refused. Branch removal returns a useful validation message when users remain assigned, including for platform admins. No access controls were bypassed.

The system-wide test run also exposed a SQLite billing-report date-boundary issue; date-only comparisons now include invoices issued on the selected end date.

Recent additions: receipt-based tracking suffixes with duplicate protection, backdated status event times, acting-company shipment validation, and country dropdowns. See the companion feature documents in this folder.

## Release limits

Verification: full suite passed with 353 tests and 1,446 assertions on isolated in-memory SQLite; TypeScript and production frontend build passed. Local /login returned HTTP 200, and the GLX administrator's active-branch check returned true after restoration.

The implemented core modules have automated coverage, but this is a local development installation, not a deployed production service. Production still requires configured MySQL/Redis infrastructure as appropriate, a Linux queue/Horizon supervisor, Reverb, a scheduler, real carrier/email provider configuration, TLS, and a tested backup/restore procedure. See DEPLOYMENT.md. This repair did not verify live external integrations or production operations.

## Missing-feature completion

Implemented after the repair pass: My account (profile/password, database sessions, expiring self-service API tokens), authenticator 2FA enrollment/confirmation/disable/recovery with replay protection, enforced web/API second factors, a private paginated notifications inbox, and authorized failed-webhook retry. The billing date display now uses date-only text. Migration 0015 was applied without resetting local data.

See ACCOUNT_AND_INBOX.md for usage and implementation, and API_CONSUMER_GUIDE.md for the supported API contract. These replace the earlier notes listing 2FA, tokens and inbox as missing. Production infrastructure and real external-provider activation remain separate from implemented application features. Historical accessibility notes about older forms are not a claim of an accessibility certification; the new forms use associated labels.

Final completion verification: 366 tests passed with 1,578 assertions on isolated in-memory SQLite. TypeScript checking and the production frontend build passed. The running GLX account page and notifications inbox were checked in the browser, including the corrected phone-field autofill hints. No live account password, authenticator enrollment, or API token was changed during these browser checks.
