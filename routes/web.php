<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredWorkspaceController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\InvoiceItemController;
use App\Http\Controllers\Billing\InvoiceTransitionController;
use App\Http\Controllers\Billing\GatewayController as BillingGatewayController;
use App\Http\Controllers\Billing\PaymentController;
use App\Http\Controllers\Billing\RateCardController;
use App\Http\Controllers\Billing\RateCardTierController;
use App\Http\Controllers\Crm\CustomerAddressController;
use App\Http\Controllers\Crm\CustomerContactController;
use App\Http\Controllers\Crm\CustomerController;
use App\Http\Controllers\Crm\CustomerImportController;
use App\Http\Controllers\Crm\CustomerLookupController;
use App\Http\Controllers\Crm\CustomerNoteController;
use App\Http\Controllers\Crm\CustomerPortalController;
use App\Http\Controllers\Customs\CustomsClearanceController;
use App\Http\Controllers\Customs\CustomsClearanceTransitionController;
use App\Http\Controllers\Customs\CustomsDutyController;
use App\Http\Controllers\Customs\CustomsInspectionController;
use App\Http\Controllers\Customs\DocumentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Delivery\CodRemittanceController;
use App\Http\Controllers\Delivery\DeliveryAssignmentController;
use App\Http\Controllers\Delivery\DeliveryAssignmentTransitionController;
use App\Http\Controllers\Delivery\DeliveryAttemptController;
use App\Http\Controllers\Delivery\DeliveryZoneController;
use App\Http\Controllers\Delivery\DispatchBoardController;
use App\Http\Controllers\Delivery\DriverController;
use App\Http\Controllers\Delivery\DriverLocationController;
use App\Http\Controllers\Delivery\MyDeliveriesController;
use App\Http\Controllers\Delivery\VehicleController;
use App\Http\Controllers\FailedJobController;
use App\Http\Controllers\Freight\LoadUnitController;
use App\Http\Controllers\Freight\LoadUnitTransitionController;
use App\Http\Controllers\Freight\ManifestController;
use App\Http\Controllers\Freight\MasterController;
use App\Http\Controllers\Freight\MasterTransitionController;
use App\Http\Controllers\Freight\PackageLoadController;
use App\Http\Controllers\Freight\RouteLegController;
use App\Http\Controllers\Freight\RouteLegTransitionController;
use App\Http\Controllers\Identity\InvitationController;
use App\Http\Controllers\Notifications\InboxController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Platform\ActingCompanyController;
use App\Http\Controllers\Platform\GatewayController;
use App\Http\Controllers\Platform\PlatformAdminController;
use App\Http\Controllers\Platform\PlatformSettingsController;
use App\Http\Controllers\Platform\RegistrationSettingsController;
use App\Http\Controllers\Platform\WorkspaceController;
use App\Http\Controllers\Platform\WorkspaceUserController;
use App\Http\Controllers\Public\PublicTrackingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Setup\SetupController;
use App\Http\Controllers\Settings\BatchNumberSettingsController;
use App\Http\Controllers\Settings\BranchController;
use App\Http\Controllers\Settings\CarrierController;
use App\Http\Controllers\Settings\CompanyController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\ShipmentStatusController;
use App\Http\Controllers\Settings\TrackingNumberSettingsController;
use App\Http\Controllers\Settings\UserController;
use App\Http\Controllers\Settings\UserRoleController;
use App\Http\Controllers\Shipments\BatchShipmentController;
use App\Http\Controllers\Shipments\BatchTransitionController;
use App\Http\Controllers\Shipments\BoxController;
use App\Http\Controllers\Shipments\BoxSizeController;
use App\Http\Controllers\Shipments\ShipmentBarcodeController;
use App\Http\Controllers\Shipments\ShipmentBatchController;
use App\Http\Controllers\Shipments\ShipmentController;
use App\Http\Controllers\Shipments\ShipmentPackageController;
use App\Http\Controllers\Shipments\ShipmentPartyController;
use App\Http\Controllers\Shipments\ShipmentTransitionController;
use App\Http\Controllers\Warehouse\ScanBoardController;
use App\Http\Controllers\Warehouse\WarehouseController;
use App\Http\Controllers\Warehouse\WarehouseLocationController;
use App\Http\Controllers\Warehouse\WarehouseScanController;
use App\Http\Controllers\Warehouse\WarehouseZoneController;
use App\Http\Controllers\Webhooks\WebhookEndpointController;
use App\Http\Controllers\Webhooks\WebhookRetryController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// One evergreen link for every customer — /track alone, with nothing
// pre-filled — meant to be published on the company's own site/emails once
// (nav link, footer, order confirmation) rather than minted fresh per
// shipment. /track/{trackingNumber} still exists underneath it for a link
// that should land straight on one shipment's result (e.g. a tracking-page
// URL texted to that one customer, or the QR/barcode on a label).
//
// /track/embed and /track/{trackingNumber}/embed are the embeddable
// counterparts — see EmbedCard on Public/Tracking.tsx for the snippets
// they're rendered by. A higher limit than the main pages since an embed
// left open polls itself every 60s (Public/TrackingEmbed.tsx) for as long
// as a visitor has it open, on top of whatever traffic the embedding site
// itself gets.
//
// /track/embed MUST be registered before /track/{trackingNumber} — both
// match a bare "/track/embed" request, and the router takes whichever
// matching route was registered first, so the order here is load-bearing,
// not cosmetic.
Route::get('/track', [PublicTrackingController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('public.tracking.search');

Route::get('/track/embed', [PublicTrackingController::class, 'embed'])
    ->middleware('throttle:120,1')
    ->name('public.tracking.searchEmbed');

Route::get('/track/{trackingNumber}', [PublicTrackingController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('public.tracking.show');

Route::get('/track/{trackingNumber}/embed', [PublicTrackingController::class, 'embed'])
    ->middleware('throttle:120,1')
    ->name('public.tracking.embed');

// First-run setup. Open only until the first superadmin exists; both routes
// 404 after that.
Route::middleware('guest')->group(function (): void {
    Route::get('/setup', [SetupController::class, 'create'])->name('setup.create');
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:5,1')->name('setup.store');
});

Route::middleware('guest')->group(function (): void {
    // Public workspace sign-up. 404s unless a superadmin has switched it on.
    Route::get('/register', [RegisteredWorkspaceController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredWorkspaceController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
    Route::get('/invitations/{user}', [InvitationController::class, 'show'])->middleware('signed')->name('invitation.accept');
    Route::post('/invitations/{user}', [InvitationController::class, 'store'])->middleware('signed')->name('invitation.store');
});

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', EmailVerificationNotificationController::class)->middleware('throttle:6,1')->name('verification.send');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::middleware('verified')->group(function (): void {
        Route::post('/settings/webhook-deliveries/{delivery}/retry', [WebhookRetryController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/account', [AccountController::class, 'show'])->name('account.show');
        Route::patch('/account', [AccountController::class, 'update'])->middleware('throttle:6,1');
        Route::post('/account/security/{action}', [AccountController::class, 'security'])->middleware('throttle:6,1');
        Route::post('/account/tokens', [AccountController::class, 'token'])->middleware('throttle:6,1');
        Route::delete('/account/tokens/{token}', [AccountController::class, 'revoke'])->middleware('throttle:6,1');
        Route::get('/notifications', [InboxController::class, 'index']);
        Route::post('/notifications/read-all', [InboxController::class, 'readAll']);
        Route::patch('/notifications/{notification}', [InboxController::class, 'update']);
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/superadmin', [WorkspaceController::class, 'index']);
        Route::post('/superadmin/workspaces', [WorkspaceController::class, 'store'])->middleware('throttle:6,1,workspace-create');
        Route::patch('/superadmin/workspaces/{company}', [WorkspaceController::class, 'update'])->middleware('throttle:10,1,workspace-update');
        Route::patch('/superadmin/registration', [RegistrationSettingsController::class, 'update'])->name('superadmin.registration.update');
        Route::get('/superadmin/users', [WorkspaceUserController::class, 'index']);
        Route::get('/superadmin/users/{user}', [WorkspaceUserController::class, 'show']);
        Route::patch('/superadmin/users/{user}', [WorkspaceUserController::class, 'update'])->middleware('throttle:10,1,workspace-user-update');
        Route::post('/superadmin/users/{user}/{action}', [WorkspaceUserController::class, 'action'])->middleware('throttle:6,1,workspace-user-action');
        Route::post('/settings/platform/stripe/test', [GatewayController::class, 'test'])->middleware('throttle:3,1,stripe-test');
        Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('search');

        Route::prefix('platform')->name('platform.')->group(function (): void {
            Route::post('/act-as/{company}', [ActingCompanyController::class, 'store'])->name('actAs.store');
            Route::delete('/act-as', [ActingCompanyController::class, 'destroy'])->name('actAs.destroy');
        });

        Route::prefix('reports')->name('reports.')->group(function (): void {
            Route::get('/shipments', [ReportController::class, 'shipments'])->name('shipments');
            Route::get('/shipments/export', [ReportController::class, 'exportShipments'])->name('shipments.export');
            Route::get('/billing', [ReportController::class, 'billing'])->name('billing');
            Route::get('/billing/export', [ReportController::class, 'exportBilling'])->name('billing.export');
        });

        Route::prefix('settings')->name('settings.')->group(function (): void {
            Route::get('/company', [CompanyController::class, 'show'])->name('company.show');
            Route::patch('/company', [CompanyController::class, 'update'])->name('company.update');
            Route::get('/tracking', [TrackingNumberSettingsController::class, 'index'])->name('tracking.index');
            Route::patch('/tracking', [TrackingNumberSettingsController::class, 'update'])->name('tracking.update');
            Route::get('/batches', [BatchNumberSettingsController::class, 'index'])->name('batches.index');
            Route::patch('/batches', [BatchNumberSettingsController::class, 'update'])->name('batches.update');

            Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
            Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
            Route::patch('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
            Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
            Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
            Route::post('/users/{user}/roles', [UserRoleController::class, 'store'])->name('users.roles.store');
            Route::delete('/users/{user}/roles/{role}', [UserRoleController::class, 'destroy'])->name('users.roles.destroy');

            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
            Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

            Route::get('/webhook-endpoints', [WebhookEndpointController::class, 'index'])->name('webhookEndpoints.index');
            Route::post('/webhook-endpoints', [WebhookEndpointController::class, 'store'])->name('webhookEndpoints.store');
            Route::patch('/webhook-endpoints/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->name('webhookEndpoints.update');
            Route::delete('/webhook-endpoints/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->name('webhookEndpoints.destroy');

            Route::get('/notifications', [NotificationPreferenceController::class, 'index'])->name('notifications.index');
            Route::patch('/notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');

            Route::get('/audit-log', [AuditLogController::class, 'index'])->name('auditLog.index');

            Route::get('/shipment-statuses', [ShipmentStatusController::class, 'index'])->name('shipmentStatuses.index');
            Route::post('/shipment-statuses', [ShipmentStatusController::class, 'store'])->name('shipmentStatuses.store');
            Route::patch('/shipment-statuses/{shipmentStatus}', [ShipmentStatusController::class, 'update'])->name('shipmentStatuses.update');
            Route::delete('/shipment-statuses/{shipmentStatus}', [ShipmentStatusController::class, 'destroy'])->name('shipmentStatuses.destroy');

            Route::get('/carriers', [CarrierController::class, 'index'])->name('carriers.index');
            Route::post('/carriers', [CarrierController::class, 'store'])->name('carriers.store');
            Route::patch('/carriers/{carrier}', [CarrierController::class, 'update'])->name('carriers.update');
            Route::post('/carriers/{carrier}/active', [CarrierController::class, 'setActive'])->name('carriers.setActive');

            Route::get('/boxes', [BoxController::class, 'index'])->name('boxes.index');
            Route::post('/boxes', [BoxController::class, 'store'])->name('boxes.store');
            Route::patch('/boxes/{box}', [BoxController::class, 'update'])->name('boxes.update');
            Route::post('/boxes/{box}/active', [BoxController::class, 'setActive'])->name('boxes.setActive');
            Route::delete('/boxes/{box}', [BoxController::class, 'destroy'])->name('boxes.destroy');
            Route::post('/boxes/{box}/sizes', [BoxSizeController::class, 'store'])->name('boxes.sizes.store');
            Route::patch('/boxes/{box}/sizes/{size}', [BoxSizeController::class, 'update'])->name('boxes.sizes.update');
            Route::post('/boxes/{box}/sizes/{size}/active', [BoxSizeController::class, 'setActive'])->name('boxes.sizes.setActive');
            Route::delete('/boxes/{box}/sizes/{size}', [BoxSizeController::class, 'destroy'])->name('boxes.sizes.destroy');

            Route::get('/failed-jobs', [FailedJobController::class, 'index'])->name('failedJobs.index');
            Route::post('/failed-jobs/{uuid}/retry', [FailedJobController::class, 'retry'])->name('failedJobs.retry');
            Route::delete('/failed-jobs/{uuid}', [FailedJobController::class, 'destroy'])->name('failedJobs.destroy');

            // Platform-wide configuration: branding, outgoing mail, the
            // payment gateway, and the platform-admin roster itself. Gated to
            // system-configuration.manage (platform_only, so effectively
            // is_platform_admin — see PlatformSettingsController) rather than
            // any company permission, since none of it belongs to a tenant.
            Route::prefix('platform')->name('platform.')->group(function (): void {
                Route::get('/', [PlatformSettingsController::class, 'show'])->name('show');
                Route::patch('/general', [PlatformSettingsController::class, 'updateGeneral'])->name('general.update');
                Route::post('/branding', [PlatformSettingsController::class, 'updateBranding'])->name('branding.update');
                Route::patch('/smtp', [PlatformSettingsController::class, 'updateSmtp'])->name('smtp.update');
                Route::post('/smtp/test', [PlatformSettingsController::class, 'sendTestEmail'])->name('smtp.test');
                Route::patch('/stripe', [PlatformSettingsController::class, 'updateStripe'])->name('stripe.update');
                Route::post('/stripe/subscriptions', [GatewayController::class, 'createSubscription'])->name('stripe.subscriptions.store');
                Route::post('/stripe/subscriptions/{subscription}/cancel', [GatewayController::class, 'cancelSubscription'])->name('stripe.subscriptions.cancel');

                Route::prefix('admins')->name('admins.')->group(function (): void {
                    Route::get('/', [PlatformAdminController::class, 'index'])->name('index');
                    Route::post('/', [PlatformAdminController::class, 'store'])->name('store');
                    Route::post('/{admin}/suspend', [PlatformAdminController::class, 'suspend'])->name('suspend');
                    Route::post('/{admin}/reactivate', [PlatformAdminController::class, 'reactivate'])->name('reactivate');
                });
            });
        });

        Route::prefix('crm')->name('crm.')->group(function (): void {
            Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::post('/customers/import', [CustomerImportController::class, 'store'])->name('customers.import');
            // Registered before the {customer} route so "lookup" is not taken
            // as a customer key. Throttled: it is typed against, not clicked.
            Route::get('/customers/lookup', CustomerLookupController::class)->middleware('throttle:120,1')->name('customers.lookup');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

            Route::post('/customers/{customer}/contacts', [CustomerContactController::class, 'store'])->name('customers.contacts.store');
            Route::patch('/customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'update'])->name('customers.contacts.update');
            Route::delete('/customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'destroy'])->name('customers.contacts.destroy');

            Route::post('/customers/{customer}/addresses', [CustomerAddressController::class, 'store'])->name('customers.addresses.store');
            Route::patch('/customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update'])->name('customers.addresses.update');
            Route::delete('/customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'destroy'])->name('customers.addresses.destroy');

            Route::post('/customers/{customer}/notes', [CustomerNoteController::class, 'store'])->name('customers.notes.store');
            Route::delete('/customers/{customer}/notes/{note}', [CustomerNoteController::class, 'destroy'])->name('customers.notes.destroy');

            Route::post('/customers/{customer}/portal', [CustomerPortalController::class, 'store'])->name('customers.portal.store');
            Route::delete('/customers/{customer}/portal', [CustomerPortalController::class, 'destroy'])->name('customers.portal.destroy');

            Route::post('/customers/{customer}/invoices', [InvoiceController::class, 'store'])->name('customers.invoices.store');
            Route::get('/customers/{customer}/invoices/{invoice}', [InvoiceController::class, 'show'])->name('customers.invoices.show');
            Route::post('/customers/{customer}/invoices/{invoice}/items', [InvoiceItemController::class, 'store'])->name('customers.invoices.items.store');
            Route::delete('/customers/{customer}/invoices/{invoice}/items/{item}', [InvoiceItemController::class, 'destroy'])->name('customers.invoices.items.destroy');
            Route::post('/customers/{customer}/invoices/{invoice}/transitions', [InvoiceTransitionController::class, 'store'])->name('customers.invoices.transitions.store');
            Route::post('/customers/{customer}/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('customers.invoices.payments.store');
            Route::post('/customers/{customer}/invoices/{invoice}/checkout', [BillingGatewayController::class, 'create'])->name('customers.invoices.checkout');
            Route::post('/customers/{customer}/invoices/{invoice}/payments/{payment}/refund', [BillingGatewayController::class, 'refund'])->name('customers.invoices.payments.refund');
        });

        Route::prefix('batches')->name('batches.')->group(function (): void {
            Route::get('/', [ShipmentBatchController::class, 'index'])->name('index');
            Route::post('/', [ShipmentBatchController::class, 'store'])->name('store');
            Route::get('/{batch}', [ShipmentBatchController::class, 'show'])->name('show');
            Route::patch('/{batch}', [ShipmentBatchController::class, 'update'])->name('update');
            Route::delete('/{batch}', [ShipmentBatchController::class, 'destroy'])->name('destroy');

            Route::post('/{batch}/shipments', [BatchShipmentController::class, 'store'])->name('shipments.store');
            Route::delete('/{batch}/shipments/{shipment}', [BatchShipmentController::class, 'destroy'])->name('shipments.destroy');

            Route::post('/{batch}/transitions', [BatchTransitionController::class, 'store'])->name('transitions.store');
        });

        Route::prefix('shipments')->name('shipments.')->group(function (): void {
            Route::get('/', [ShipmentController::class, 'index'])->name('index');
            Route::post('/', [ShipmentController::class, 'store'])->name('store');
            Route::get('/{shipment}', [ShipmentController::class, 'show'])->name('show');
            Route::patch('/{shipment}', [ShipmentController::class, 'update'])->name('update');
            Route::delete('/{shipment}', [ShipmentController::class, 'destroy'])->name('destroy');

            Route::post('/{shipment}/transitions', [ShipmentTransitionController::class, 'store'])->name('transitions.store');

            Route::patch('/{shipment}/parties/{party}', [ShipmentPartyController::class, 'update'])->name('parties.update');

            Route::post('/{shipment}/packages', [ShipmentPackageController::class, 'store'])->name('packages.store');
            Route::patch('/{shipment}/packages/{package}', [ShipmentPackageController::class, 'update'])->name('packages.update');
            Route::delete('/{shipment}/packages/{package}', [ShipmentPackageController::class, 'destroy'])->name('packages.destroy');

            Route::get('/{shipment}/barcode.svg', [ShipmentBarcodeController::class, 'shipment'])->name('barcode');
            Route::get('/{shipment}/qr.svg', [ShipmentBarcodeController::class, 'shipmentQr'])->name('qr');
            Route::get('/{shipment}/packages/{package}/barcode.svg', [ShipmentBarcodeController::class, 'package'])->name('packages.barcode');

            Route::post('/{shipment}/legs', [RouteLegController::class, 'store'])->name('legs.store');
            Route::delete('/{shipment}/legs/{leg}', [RouteLegController::class, 'destroy'])->name('legs.destroy');
            Route::post('/{shipment}/legs/{leg}/transitions', [RouteLegTransitionController::class, 'store'])->name('legs.transitions.store');

            Route::post('/{shipment}/customs-clearances', [CustomsClearanceController::class, 'store'])->name('customsClearances.store');
            Route::get('/{shipment}/customs-clearances/{clearance}', [CustomsClearanceController::class, 'show'])->name('customsClearances.show');
            Route::post('/{shipment}/customs-clearances/{clearance}/transitions', [CustomsClearanceTransitionController::class, 'store'])->name('customsClearances.transitions.store');

            Route::post('/{shipment}/customs-clearances/{clearance}/duties', [CustomsDutyController::class, 'store'])->name('customsClearances.duties.store');
            Route::post('/{shipment}/customs-clearances/{clearance}/duties/{duty}/pay', [CustomsDutyController::class, 'pay'])->name('customsClearances.duties.pay');

            Route::post('/{shipment}/customs-clearances/{clearance}/inspections', [CustomsInspectionController::class, 'store'])->name('customsClearances.inspections.store');
            Route::post('/{shipment}/customs-clearances/{clearance}/inspections/{inspection}/complete', [CustomsInspectionController::class, 'complete'])->name('customsClearances.inspections.complete');

            Route::post('/{shipment}/customs-clearances/{clearance}/documents', [DocumentController::class, 'store'])->name('customsClearances.documents.store');
            Route::get('/{shipment}/customs-clearances/{clearance}/documents/{document}/download', [DocumentController::class, 'download'])->name('customsClearances.documents.download');
            Route::delete('/{shipment}/customs-clearances/{clearance}/documents/{document}', [DocumentController::class, 'destroy'])->name('customsClearances.documents.destroy');

            Route::post('/{shipment}/delivery-assignments', [DeliveryAssignmentController::class, 'store'])->name('deliveryAssignments.store');
            Route::get('/{shipment}/delivery-assignments/{assignment}', [DeliveryAssignmentController::class, 'show'])->name('deliveryAssignments.show');
            Route::post('/{shipment}/delivery-assignments/{assignment}/transitions', [DeliveryAssignmentTransitionController::class, 'store'])->name('deliveryAssignments.transitions.store');
            Route::post('/{shipment}/delivery-assignments/{assignment}/attempts', [DeliveryAttemptController::class, 'store'])->name('deliveryAssignments.attempts.store');
            Route::get('/{shipment}/delivery-assignments/{assignment}/attempts/{attempt}/pod.pdf', [DeliveryAttemptController::class, 'pod'])->name('deliveryAssignments.attempts.pod');
        });

        Route::prefix('freight')->name('freight.')->group(function (): void {
            Route::get('/masters', [MasterController::class, 'index'])->name('masters.index');
            Route::post('/masters', [MasterController::class, 'store'])->name('masters.store');
            Route::get('/masters/{master}', [MasterController::class, 'show'])->name('masters.show');
            Route::post('/masters/{master}/transitions', [MasterTransitionController::class, 'store'])->name('masters.transitions.store');

            Route::post('/masters/{master}/load-units', [LoadUnitController::class, 'store'])->name('masters.loadUnits.store');
            Route::post('/masters/{master}/load-units/{unit}/transitions', [LoadUnitTransitionController::class, 'store'])->name('masters.loadUnits.transitions.store');
            Route::post('/masters/{master}/load-units/{unit}/packages', [PackageLoadController::class, 'store'])->name('masters.loadUnits.packages.store');
            Route::delete('/masters/{master}/load-units/{unit}/packages/{package}', [PackageLoadController::class, 'destroy'])->name('masters.loadUnits.packages.destroy');

            Route::post('/masters/{master}/manifests', [ManifestController::class, 'store'])->name('masters.manifests.store');
            Route::get('/masters/{master}/manifests/{manifest}', [ManifestController::class, 'show'])->name('masters.manifests.show');
        });

        Route::prefix('warehouses')->name('warehouses.')->group(function (): void {
            Route::get('/', [WarehouseController::class, 'index'])->name('index');
            Route::post('/', [WarehouseController::class, 'store'])->name('store');
            Route::get('/{warehouse}', [WarehouseController::class, 'show'])->name('show');
            Route::patch('/{warehouse}', [WarehouseController::class, 'update'])->name('update');

            Route::post('/{warehouse}/zones', [WarehouseZoneController::class, 'store'])->name('zones.store');
            Route::post('/{warehouse}/zones/{zone}/locations', [WarehouseLocationController::class, 'store'])->name('zones.locations.store');

            Route::get('/{warehouse}/scan-board', [ScanBoardController::class, 'show'])->name('scanBoard');
            Route::post('/{warehouse}/scans', [WarehouseScanController::class, 'store'])->name('scans.store');
        });

        Route::get('/drivers', [DriverController::class, 'index'])->name('drivers.index');
        Route::post('/drivers', [DriverController::class, 'store'])->name('drivers.store');
        Route::patch('/drivers/{driver}', [DriverController::class, 'update'])->name('drivers.update');

        Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
        Route::post('/vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
        Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');

        Route::get('/delivery-zones', [DeliveryZoneController::class, 'index'])->name('deliveryZones.index');
        Route::post('/delivery-zones', [DeliveryZoneController::class, 'store'])->name('deliveryZones.store');

        Route::get('/deliveries/dispatch-board', [DispatchBoardController::class, 'show'])->name('deliveries.dispatchBoard');
        Route::get('/my-deliveries', [MyDeliveriesController::class, 'index'])->name('deliveries.mine');

        Route::get('/customs/queue', [CustomsClearanceController::class, 'queue'])->name('customsClearances.queue');

        Route::post('/driver-locations', [DriverLocationController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('driverLocations.store');

        Route::get('/rate-cards', [RateCardController::class, 'index'])->name('rateCards.index');
        Route::post('/rate-cards', [RateCardController::class, 'store'])->name('rateCards.store');
        Route::post('/rate-cards/{rateCard}/tiers', [RateCardTierController::class, 'store'])->name('rateCards.tiers.store');

        Route::get('/cod-remittances', [CodRemittanceController::class, 'index'])->name('codRemittances.index');
        Route::post('/cod-remittances', [CodRemittanceController::class, 'store'])->name('codRemittances.store');
    });
});
