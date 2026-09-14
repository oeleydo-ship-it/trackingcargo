# Superadmin console

## Entry points and authority

Open `/superadmin` using an existing platform-administrator account. The sidebar now has a Superadmin link visible only to platform administrators. Every new endpoint also checks the server-side `is_platform_admin` flag. Company administrators cannot obtain this authority through company roles, a company ID in a request, or an acting-company session value.

The console intentionally spans all workspaces even when the administrator has selected an acting company. Ordinary operational screens still use that selected company. `Manage workspace` selects a company and opens its existing settings. `/superadmin/users` is the global user directory; `/settings/platform/admins` remains the separate privileged-admin roster with self-suspension protection.

## Implemented controls

- Search/filter/paginate companies and view workspace/user totals.
- Create a workspace, first branch, default shipment workflow, company-only administrator role and queued administrator invitation in one database transaction. Invitation delivery waits until commit. The first administrator has company-wide access and must accept their invitation to set a password.
- Rename, suspend, deactivate or reactivate a workspace with an audit reason. Suspension/deactivation preserves records and revokes its users' API tokens, remembered logins and database sessions. Other workspaces are untouched.
- Search/filter/paginate all company users. View 2FA enrollment state without secrets.
- Edit name, phone, branch and company roles with an audit reason. A user cannot be moved to another company or promoted to platform administrator through this form. Role and branch IDs must belong to the user's company. Changes revoke prior access.
- Suspend/reactivate users, revoke access, resend invitations and send password-reset links. Email addresses/passwords/2FA secrets are not exposed for editing here. Unaccepted invitations cannot be activated to bypass acceptance; resend invitation can resume a suspended unverified invitation.
- Links to existing platform-admin invitations, SMTP/test email, branding, general defaults, audit logs and failed-job recovery.
- Stripe credential-format and test/live-mode consistency validation, encrypted storage, secret omission from serialization and validation flash data, plus a read-only connection check.

## SMTP

Save host, port, username/password, sender details and encryption in Settings → Platform. TLS maps to the mailer's `smtp` scheme with required STARTTLS; SSL maps to `smtps`. SMTP exception details are not displayed because they may contain connection or credential information.

Use Send test email to verify a real recipient only when ready. Invitation delivery needs the configured queue worker. Restart long-running queue workers after changing stored SMTP settings so they reload their configuration. Tests use fake notifications and instantiate transports without connecting.

## Payment-gateway boundary

Stripe is the existing supported provider for credential administration. The connection button uses a read-only authenticated `GET /v1/balance`, displays only test/live connection success and neither stores balances nor creates a charge. A restricted key must allow balance reads. Reference: [Stripe retrieve-balance API](https://docs.stripe.com/api/balance/balance_retrieve?lang=php).

This is gateway administration, **not an implemented online invoice checkout**. Hosted checkout, signed inbound payment webhooks, idempotent invoice settlement, refunds, recurring workspace subscriptions and additional gateways remain separate implementation work. Do not advertise online payment acceptance merely because keys are saved or the connection check succeeds. Decide the merchant/settlement model (one platform merchant versus separate company merchants) before implementing collection across independent companies.

## Operational safeguards and handoff

- No local company/user data was changed during implementation, and no database migration is needed.
- Session revocation supports the configured database session driver. Keep database sessions for these controls; other session stores require their own revocation mechanism.
- No hard-delete controls were added. No customer records are removed by suspension.
- Enrollment/recovery of platform-admin 2FA remains self-service under My account; there is deliberately no silent 2FA bypass in the global user form.
- Workspace administrator roles use the installed permission catalog. Installations must have run the authorization seed as part of initial setup; do not reset or reseed existing user data for this feature.
- Backend: WorkspaceController, WorkspaceUserController, UserAccessService, GatewayController. React: Pages/Superadmin and layouts/SuperadminLayout. Tests: SuperadminConsoleTest plus existing platform, identity and tenant-isolation suites.
