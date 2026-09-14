# Phase 9 checklist — realtime, notifications, webhooks, and carrier adapters

## Objective

Turn CargoFlow from a system that reacts only to direct user requests into
one that pushes information outward: staff see live updates over
Reverb/Echo, users get notified (mail, in-app, realtime) through channels
they control, external systems receive signed and retried webhooks for the
events they care about, and a pluggable carrier-provider registry keeps
tracking data moving even when nobody is looking at the app.

## Scope decisions (read before extending this phase)

- **Reverb/Echo/Horizon were already scaffolded in Phase 0 but never
  actually turned on or exercised.** `config/horizon.php` already defined
  the exact queue topology this phase needed (`tracking`/`webhooks`/
  `notifications` queues already listed under the `operations`/
  `side-effects` supervisors), `AppServiceProvider::boot()` already wired
  `Horizon::auth()`, and `resources/js/bootstrap.js` already conditionally
  initialized `window.Echo`. This phase's job was mostly to actually flip
  `QUEUE_CONNECTION=redis`/`BROADCAST_CONNECTION=reverb`, generate real
  Reverb credentials, and — because nothing had ever really connected
  before — find and fix what broke the moment it was turned on for real
  (see "Real bugs" below).
- **Notification channels are chosen per-user, per-type, via
  `NotificationPreferenceService`** (`notification_preferences`: unique
  `(user_id, notification_type, channel)`), not hardcoded in each
  notification's `via()`. This is opt-out, not opt-in — a channel with no
  preference row is on by default — appropriate for a B2B ops tool where a
  missed delivery/invoice notice is worse than one extra email. Only two
  concrete notifications were wired to real domain events this phase
  (`ShipmentDeliveredNotification`, `InvoiceIssuedNotification`, both to
  the customer's linked portal user) plus one ops-facing one
  (`WebhookDeliveryFailedNotification`, to every `company-admin`) — not
  every domain action in the app, to keep this phase's surface
  proportionate to its own deliverables rather than retrofitting
  notifications everywhere.
- **Webhooks reuse the exact idempotent shape from every prior phase's
  "check idempotency_key, unique-constraint-and-refetch" services** — but
  applied at the *delivery* level, not the dispatch level:
  `WebhookDispatchService::dispatch()` fans one event out to every active,
  subscribed `WebhookEndpoint`, writing one `WebhookDelivery` row per
  target (its own fresh UUID `idempotency_key`) and queuing one
  `SendWebhookJob` per row on the `webhooks` queue. Dispatch always happens
  *after* the triggering `DB::transaction()` commits (`ShipmentTransitionService`
  and `PaymentService` are the two call sites), so a rejected/rolled-back
  action never sends anything — proven directly by
  `WebhookDeliveryTest::test_a_rejected_transition_sends_no_webhook_at_all`.
  Only two event types exist (`shipment.status_changed`, `invoice.paid`)
  for the same "small and meaningful, not everything" reasoning as
  notifications.
- **Signing is HMAC-SHA256 over the raw JSON body, documented in
  `docs/WEBHOOKS.md`** with a receiver-side verification snippet in two
  languages, and the exact algorithm is independently reproduced inside
  `WebhookDeliveryTest` (recomputes the signature from the captured
  outbound request and asserts it matches the header CargoFlow actually
  sent) — satisfying "documented signature verification passes" as a real,
  checked claim rather than prose alone.
- **`WebhookEndpoint.secret` follows the established "reveal once" secret
  pattern**: `$hidden = ['secret']` on the model (never serializes back to
  the frontend after creation), shown exactly once in the creation
  response's flash message.
- **The carrier provider registry is a real registry (multiple named
  providers), not a single bound interface** like Phase 7's
  `AssignmentStrategy` — `CarrierProviderRegistry` resolves a
  `CarrierProviderInterface` by code from `config/carriers.php` (code =>
  class map), since a company can plausibly use more than one carrier at
  once, whereas assignment strategy is one company-wide policy.
- **The only concrete carrier provider is `MockCarrierProvider`, an
  explicitly-labeled reference/demo implementation** — there is no real
  third-party carrier API available to integrate against in this
  environment, so this simulates a plausible feed (time-based progression)
  rather than faking a fake integration. It is deliberately restricted to
  **pre-customs progression only** (`received -> in_transit -> at_customs`):
  `delivered` stays exclusively POD-gated by `DeliveryAttemptService`
  (Phase 7's structural guarantee) and customs clearance stays exclusively
  owned by `CustomsClearance` (Phase 6) — an automated carrier feed must
  never shortcut either state machine, so it never reports a mapped status
  past `at_customs`. A shipment opts into a provider via a new nullable
  `shipments.carrier_code`, validated against the registry's known codes at
  booking time.
- **Tracking polling is a scheduled queued job
  (`PollCarrierTrackingJob`, `routes/console.php`'s `Schedule::job(...)->everyFiveMinutes()`),
  not a synchronous request-time check** — it iterates every company
  (Architecture: "Background jobs must carry the company identifier and
  restore tenant context before loading tenant models"), restoring
  `TenantContext` per company exactly like `AuthorizationSeeder`/
  `DatabaseSeeder` already do, and routes every mapped status change
  through the same `ShipmentTransitionService::transition()` every other
  phase already uses — which is also what makes the
  `shipment.status_changed` webhook and the delivered-notification fire
  correctly for carrier-driven transitions too, with zero special-casing.
  `ShipmentTransitionService::transition()`'s `$actor` parameter is now
  nullable to support this system-driven case; `AuditService` and
  `tracking_events.created_by` already supported a null actor before this
  phase, so this was a narrow, backward-compatible change.

## Deliverables

- [x] Reverb/Echo: real app credentials generated, `BROADCAST_CONNECTION=reverb`,
      `window.Echo` connects for real (previously never verified — see
      "Real bugs"). Existing `company.{companyId}`/`user.{userId}` private
      channels (Phase 5+) now proven to authorize correctly end to end.
- [x] Redis/Horizon queues: `QUEUE_CONNECTION=redis`; Phase 0's
      `config/horizon.php` queue topology now actually in use (`tracking`,
      `webhooks`, `notifications`, etc.); `Horizon::auth()` (Phase 0) now
      has something to gate. Horizon's own worker process cannot run on
      this Windows dev box (`ext-pcntl` — see "Not run"), so manual queue
      processing was verified with `queue:work redis` instead; the
      `QUEUE_CONNECTION=redis` + `horizon.php` config is what actually
      ships.
- [x] `notification_preferences` + `NotificationPreferenceService` +
      `App\Enums\NotificationType` (candidate channels per type) +
      `Settings/NotificationPreferences/Index.tsx`.
- [x] `ShipmentDeliveredNotification`, `InvoiceIssuedNotification`,
      `WebhookDeliveryFailedNotification` — mail + database (+ broadcast
      for shipment-delivered) channels, filtered per-user through
      `NotificationPreferenceService`.
- [x] `webhook_endpoints`/`webhook_deliveries`, `WebhookDispatchService`,
      `SendWebhookJob` (signed, retried with backoff, records every
      attempt), `WebhookEndpointController`/policy/Form Requests,
      `Settings/Webhooks/Index.tsx` (create/list/toggle/remove, per-endpoint
      delivery history), `docs/WEBHOOKS.md`.
- [x] `App\Contracts\CarrierProviderInterface` + `CarrierProviderRegistry`
      + `MockCarrierProvider` + `config/carriers.php`; `shipments.carrier_code`
      (validated against the registry) added to the booking form and Show
      page.
- [x] `CarrierTrackingPollService` + `PollCarrierTrackingJob`, scheduled
      every 5 minutes.

## Acceptance

- [x] **Channels prevent cross-company subscriptions.**
      `BroadcastChannelAuthorizationTest` posts to the real
      `/broadcasting/auth` endpoint (using a real Pusher-protocol driver
      configured just for this test — see the test's own docblock for why
      `BROADCAST_CONNECTION=null`/`log`, the rest of the suite's setting,
      can't exercise this) and asserts a user gets `200` for their own
      company/user channel and `403` for another company's or another
      user's — proven for both `company.{id}` and `user.{id}`.
- [x] **Rollbacks emit no external effects.**
      `WebhookDeliveryTest::test_a_rejected_transition_sends_no_webhook_at_all`
      attempts an invalid shipment transition and asserts, via
      `Http::fake()` + `Http::assertNothingSent()`, that zero HTTP requests
      were made — the guard clause runs before the transaction/dispatch
      point is ever reached. `test_an_endpoint_not_subscribed_to_the_event_receives_nothing`
      proves the fan-out itself is precise, not just "safe on failure."
- [x] **Documented signature verification passes.** `docs/WEBHOOKS.md`
      specifies the exact HMAC-SHA256-over-raw-body algorithm;
      `WebhookDeliveryTest::test_a_shipment_status_change_sends_a_correctly_signed_webhook`
      independently recomputes it from the real captured request and
      asserts it matches the `X-CargoFlow-Signature` header CargoFlow
      actually sent.

## Required test matrix

- [x] A user can authorize their own company/user broadcast channel; not
      another's (`BroadcastChannelAuthorizationTest`, 4 cases including an
      unauthenticated visitor).
- [x] A subscribed endpoint receives a correctly signed webhook; an
      unsubscribed endpoint receives nothing; a rejected domain action
      sends nothing at all; a failing delivery records the attempt/response
      status; a permanently-failed delivery is marked `failed` and notifies
      every `company-admin` (`WebhookDeliveryTest`, 5 cases).
- [x] Webhook endpoint CRUD is permission-gated, rejects non-`https://`
      URLs, never returns the secret in the listing, and is tenant-isolated
      (404 cross-company) (`WebhookEndpointManagementTest`, 4 cases).
- [x] A notification channel is enabled by default with no preference row;
      an explicit opt-out is respected by a real notification's `via()`;
      the settings endpoint persists a preference for the current user only
      and rejects a channel invalid for that type
      (`NotificationPreferenceTest`, 5 cases).
- [x] `ShipmentTransitionService` reaching `Delivered` notifies the
      customer's portal user; reaching any other status does not; issuing
      an invoice notifies the customer's portal user
      (`DomainNotificationDispatchTest`, 3 cases).
- [x] The carrier registry resolves the seeded `mock` provider; a shipment
      with no carrier or polled too recently is untouched; a stale
      `received` shipment advances to `in_transit` with a `null`-attributed
      (system) tracking event; polling never advances a shipment past
      `at_customs`; a terminal shipment is skipped entirely
      (`CarrierTrackingPollServiceTest`, 6 cases).
- [x] A shipment can be booked with a known carrier code; an unknown one is
      rejected (`ShipmentManagementTest`, 1 case added).

## Real bugs found and fixed during this phase

Turning on infrastructure that had sat configured-but-unused since Phase 0
surfaced three genuine, previously-latent bugs — none caught by writing
tests in advance, all caught by either a new test failing unexpectedly or
by manual verification:

1. **`MockCarrierProvider`'s elapsed-time check had its Carbon diff
   direction backwards.** `CarbonImmutable::now()->diffInMinutes($past)`
   returns a *negative* number in this Carbon version (it is signed, not
   absolute, by default) — so "has it been 30+ minutes" was silently always
   false for a shipment further in the past, never true. A shipment polled
   after 45 real minutes would never advance. Caught by
   `CarrierTrackingPollServiceTest::test_a_stale_received_shipment_advances_to_in_transit...`
   failing (`'received'` instead of `'in_transit'`). Fixed with `abs()`.
2. **`config/reverb.php` (hand-written since Phase 0) was missing
   `pulse_ingest_interval`/`telescope_ingest_interval` under
   `servers.reverb`**, which the `laravel/reverb` package's own
   `StartServer` command requires unconditionally. `reverb:start` crashed
   immediately with `Undefined array key "pulse_ingest_interval"` the very
   first time anyone tried to run it. Fixed by adding both keys (matching
   the package's own default config).
3. **`REVERB_ALLOWED_ORIGINS` was in the wrong format everywhere it
   appeared** (`.env`, `.env.example`, and `config/reverb.php`'s own
   fallback default) — full URLs like `http://localhost` instead of bare
   hostnames. Reverb's actual origin check
   (`Protocols\Pusher\Server::verifyOrigin()`) does
   `parse_url($origin, PHP_URL_HOST)` on the browser's `Origin` header
   before comparing, so it was comparing a bare host (`127.0.0.1`) against
   a full URL pattern (`http://127.0.0.1:8123`) — which can never match.
   Every real browser connection was rejected with Pusher error code 4009
   ("Origin not allowed"), and `window.Echo` sat permanently
   `disconnected` — exactly matching the AGENT_HANDOFF note that Echo had
   "never initialized locally," except the real cause was this, not just
   missing env vars. Caught by manually connecting a real browser tab and
   inspecting `pusher.connection.state` after fixing #2. Fixed by using
   bare hostnames (`127.0.0.1,localhost`) in all three places, with a
   comment on the config default explaining why.

## Exit commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan migrate:fresh --seed
php artisan test
npm run typecheck
npm run build
```

All of the above pass as of this phase's completion (201 tests, 638
assertions, up from 173/581). Manually verified as `glx@cargoflow.test`
with MySQL, Redis, a real `reverb:start` process, and a real
`queue:work redis` process all running: created a webhook endpoint
subscribed to `shipment.status_changed`, booked a shipment with the `mock`
carrier (the new Carrier field rendered and saved correctly), transitioned
it to `Booked` — the webhook fired for real over Redis/HTTP to
`https://example.com/...` (a safe, harmless real domain; the endpoint
correctly recorded a real `405` response and incremented `attempts`,
proving the whole redis-queue → signed-HTTP-POST → response-recording
pipeline works end to end, not just under `Http::fake()`), then disabled
the endpoint — but the already-queued job kept its own retry schedule
regardless, and was left running rather than interrupted: it retried at
the real 10s/30s/60s/5m backoff intervals against `example.com`, exhausted
all 5 tries, marked the delivery `failed`, and correctly queued
`WebhookDeliveryFailedNotification` to the company-admin — confirmed by
grepping the real rendered email out of `storage/logs/laravel.log`
(`Subject: Webhook delivery failed — GulfLink Express`). This is the
complete failure path from `docs/WEBHOOKS.md`'s "Retries" section
happening for real, unprompted, exactly as coded. Confirmed the Settings → Notifications page renders the
right candidate channels per type and that toggling a checkbox persists
across a reload. Confirmed `window.Echo`'s Pusher connection reaches
`state: "connected"` against the real local Reverb server (after fixing
the origin bug above) — the first time realtime has actually connected in
this project, not just been scaffolded.

## Not run / deliberately out of scope

- **Horizon's own supervisor process cannot run on this Windows dev
  machine** (`Call to undefined function
  Laravel\Horizon\Console\pcntl_async_signals()` — `ext-pcntl` is
  Unix-only, already documented as a known gap in `docs/AGENT_HANDOFF.md`
  since before this phase). The `QUEUE_CONNECTION=redis` architecture and
  `horizon.php` queue topology are what actually matter and are fully
  configured and exercised (via plain `queue:work`); only the Horizon
  dashboard/auto-scaling process itself is unverified locally. It will run
  normally on Linux/production.
- No true concurrent-connection race test for any idempotency-key
  constraint, for the same documented reason as every prior phase.
- No real third-party carrier integration — see "Scope decisions" above;
  `MockCarrierProvider` is explicitly a reference implementation.
- No manual "redeliver" action for a failed webhook delivery in the UI —
  re-triggering the underlying domain event is the only way to get a fresh
  delivery in this version (see `docs/WEBHOOKS.md`).
- No SMS/push notification channel — only `mail`, `database` (in-app), and
  `broadcast` (realtime) exist, matching what `NotificationType::channels()`
  actually declares; adding a channel is additive (a new candidate string
  per type) whenever a real provider is available.
- Notification database rows have no dedicated "inbox" UI page yet (they
  exist and are queryable via `$user->notifications()`, and the
  `database` channel is wired and tested, but no `/notifications` index
  page was built — out of scope for this phase's acceptance criteria,
  candidate for Phase 10's dashboards work).
