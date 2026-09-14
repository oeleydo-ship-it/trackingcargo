# Account security and notifications

## My account

Use the new My account navigation item. Update your name, phone and password; create and revoke named expiring API tokens; inspect database-backed sessions and sign out other sessions. Sensitive actions require your current password plus an unused second factor when enabled. Signing out other sessions rotates the remember token as well. API tokens are managed separately.

## Authenticator setup

Choose Set up authenticator, confirm your password, scan the QR code (or enter the setup key), then confirm a six-digit code within ten minutes. Setup is not active until confirmed. Both pending and active secrets are encrypted using APP_KEY; keep that key stable and protected.

Save the eight recovery codes immediately. They are displayed once, stored as SHA-256 hashes, and each can be used once. Replacing recovery codes invalidates the old set. An authenticator time step cannot be reused after successful verification; wait for the next code before another sensitive action. Web and API sign-in enforce 2FA after checking the password. Password reset does not remove 2FA.

Confirming setup revokes existing API tokens and other database sessions. It does not enroll another user or automatically enable 2FA on existing accounts. Never enable 2FA on a user's behalf without their authenticator and recovery-code handoff.

## Notifications

The new Notifications inbox shows only the signed-in user's database notifications, with unread counts, read/unread actions, mark-all-read, filters and pagination. It refreshes every 30 seconds while visible. Existing Settings → Notifications preferences determine which channels receive messages. Delivery still requires the notifications queue worker. No SMS/push provider was added.

## Implementation and handoff

AccountController, TwoFactorService and migration 0015 implement account security. Replay protection uses a locked user row and the most recently accepted time step; the RFC 6238 implementation is checked against RFC vectors. AccountFeaturesTest covers setup, expiry, login enforcement, recovery, password changes, token ownership, sessions and inbox privacy. ApiTokenTest exercises the API login/me/logout lifecycle. WebhookRetryTest exercises ownership and duplicate retry prevention.

Install normally, run `php artisan migrate`, then `npm run build`. Do not rebuild or reseed an existing database. Use PHP 8.3+ (the local installation uses Herd PHP 8.4). Production needs HTTPS, accurate server time, APP_KEY protection and the database session driver for session-list/revocation features.
