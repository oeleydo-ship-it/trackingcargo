# CargoFlow API consumer guide

Local base URL: `http://127.0.0.1:8001/api/v1`. Use HTTPS for production.

## Authentication

Create an expiring token in My account → API tokens. Set a name and lifetime (1–365 days), confirm your password and, if enabled, supply an unused authenticator or recovery code. Copy the token immediately; it is shown once. Tokens are stored hashed. They inherit your account's current permissions at creation and do not grant access to another company.

Alternatively send `POST /login` with JSON:

```json
{
  "email": "you@your-company.example",
  "password": "your-password",
  "device_name": "Warehouse integration",
  "code": "123456"
}
```

Omit `code` for accounts without 2FA. With 2FA enabled, supply a current six-digit authenticator code or one unused recovery code. A successful response is HTTP 201 with `data.token`, `data.token_type`, `data.expires_at`, `data.user` and `data.abilities`. The API-login lifetime uses the configured Sanctum expiration setting (default 30 days). Never log the token or password.

For authenticated requests, send `Accept: application/json` and `Authorization: Bearer YOUR_TOKEN`. Example:

```http
GET /api/v1/me HTTP/1.1
Accept: application/json
Authorization: Bearer YOUR_TOKEN
```

`GET /me` returns the token owner's profile. `DELETE /logout` revokes the presented token and returns HTTP 204. The My account page can list and revoke individual tokens; changing a password or confirming 2FA revokes existing tokens.

## Errors and limits

- 401: missing, invalid, expired or revoked token.
- 403: inactive account/company/branch or insufficient access.
- 422: invalid login or validation errors, including missing/used 2FA codes. Inspect the `errors` object.
- 429: throttled; respect Retry-After. Login is limited by both route and credential/IP throttles.

The current API exposes login, me and logout only. Shipment, finance and other operational screens use authenticated Inertia web routes; do not assume equivalent REST endpoints exist. Public tracking is available at `/track` and `/track/{trackingNumber}`. See WEBHOOKS.md for outbound domain events and signature verification.

## Webhook retries

Settings → Webhooks lists delivery status. A user with webhook-management permission may retry a failed delivery to an active endpoint. The original payload and delivery/idempotency key are reused so receivers can deduplicate. Attempts remain visible. Repeated clicks cannot enqueue a second retry while the row is pending. A running `webhooks` queue worker is required; queued does not mean delivered.
