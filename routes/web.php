<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Dashboard\AccessGovernanceController;
use App\Http\Controllers\Dashboard\AccessLogController;
use App\Http\Controllers\Dashboard\BillingController;
use App\Http\Controllers\Dashboard\BlocklistController;
use App\Http\Controllers\Dashboard\BoundaryController;
use App\Http\Controllers\Dashboard\CalendarController;
use App\Http\Controllers\Dashboard\ChangelogController;
use App\Http\Controllers\Dashboard\CompanyDashboardController;
use App\Http\Controllers\Dashboard\DeactivationController;
use App\Http\Controllers\Dashboard\DealsController;
use App\Http\Controllers\Dashboard\DelegatedAccessController;
use App\Http\Controllers\Dashboard\DigitalAccessWalletController;
use App\Http\Controllers\Dashboard\DirectoryController;
use App\Http\Controllers\Dashboard\FeedbackController;
use App\Http\Controllers\Dashboard\FundraisingController;
use App\Http\Controllers\Dashboard\GatePass\ScanGatePassController;
use App\Http\Controllers\Dashboard\GatePassController;
use App\Http\Controllers\Dashboard\GateSensorEventController;
use App\Http\Controllers\Dashboard\GuidelinesController;
use App\Http\Controllers\Dashboard\HouseholdController;
use App\Http\Controllers\Dashboard\MapController;
use App\Http\Controllers\Dashboard\NotificationController;
use App\Http\Controllers\Dashboard\OccupancyController;
use App\Http\Controllers\Dashboard\OverviewController;
use App\Http\Controllers\Dashboard\OwnershipEntityController;
use App\Http\Controllers\Dashboard\OwnershipModuleController;
use App\Http\Controllers\Dashboard\ParkingPassController;
use App\Http\Controllers\Dashboard\PaymentReturnController;
use App\Http\Controllers\Dashboard\ProfileController;
use App\Http\Controllers\Dashboard\RenterController;
use App\Http\Controllers\Dashboard\ResidentEmergencyController;
use App\Http\Controllers\Dashboard\ReviewFeedbackController;
use App\Http\Controllers\Dashboard\SafetyController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\SilentAssistanceController;
use App\Http\Controllers\Dashboard\StripeCheckoutController;
use App\Http\Controllers\Dashboard\UpdateController;
use App\Http\Controllers\Dashboard\VehicleController;
use App\Http\Controllers\Dashboard\VisitorController;
use App\Http\Controllers\Dashboard\WarningController;
use App\Http\Controllers\EventRsvpController;
use App\Http\Controllers\GuestPassController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\UniversalPaymentLinkController;
use App\Http\Controllers\UniversalSearchController;
use App\Livewire\Realtime\RealtimeOperationsHub;
use App\Livewire\Spreadsheets\SpreadsheetOperationsHub;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public pages — rendered with Blade
|--------------------------------------------------------------------------
| These replace src/app/page.tsx and src/app/privacy/page.tsx. They are
| content pages with no application state, so Blade serves them without
| shipping the React bundle.
*/

Route::get('/', [PublicPageController::class, 'landing'])->name('landing');
Route::get('/privacy', [PublicPageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [PublicPageController::class, 'terms'])->name('terms');
Route::get('/refunds', [PublicPageController::class, 'refunds'])->name('refunds');
Route::get('/cookies', [PublicPageController::class, 'cookies'])->name('cookies');
Route::get('/guest/pass/{token}', [GuestPassController::class, 'show'])->name('guest-pass.show');
Route::get('/guest/pass/{token}/safety', [SafetyController::class, 'showCredential'])->middleware('throttle:30,1')->name('credential.safety.show');
Route::post('/guest/pass/{token}/safety/assist', [SafetyController::class, 'requestCredentialAssistance'])->middleware('throttle:10,1')->name('credential.safety.assist');
Route::get('/guest/pass/{token}/code', [GuestPassController::class, 'code'])
    ->middleware('throttle:30,1')
    ->name('guest-pass.code');
Route::get('/guest/pass/{token}/pdf', [PdfController::class, 'downloadVisitorPass'])->name('pdf.visitor-pass');
Route::get('/pay/{token}', [UniversalPaymentLinkController::class, 'show'])->name('pay.show');
Route::get('/p/{token}', [UniversalPaymentLinkController::class, 'show'])->name('pay.short');
Route::get('/pay/{token}/poster', [UniversalPaymentLinkController::class, 'poster'])->name('pay.poster');
Route::post('/pay/{token}/process', [UniversalPaymentLinkController::class, 'process'])->name('pay.process');
Route::get('/rsvp/{token}', [EventRsvpController::class, 'show'])->name('rsvp.show');
Route::post('/rsvp/{token}', [EventRsvpController::class, 'submit'])
    ->middleware('throttle:30,1')
    ->name('rsvp.submit');

/*
|--------------------------------------------------------------------------
| Livewire pages — Livewire 4 + Alpine.js + Tailwind CSS
|--------------------------------------------------------------------------
| The features with no React screen yet: realtime operations chat and
| alerts, spreadsheet import/export, and universal search. Passes, the
| directory and warnings live on the React dashboard only.
|
| Signed-in, active accounts only. Livewire re-applies this middleware to
| each component update, not just the first page load.
*/
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/operations/realtime', RealtimeOperationsHub::class)->middleware('can:manageSecurity')->name('operations.realtime-hub');
    // Gated per blueprint inside the component (EnterpriseSpreadsheetService::EXPORT_GATES).
    Route::get('/operations/spreadsheets', SpreadsheetOperationsHub::class)->name('operations.spreadsheets');

    /*
    |--------------------------------------------------------------------------
    | Universal Search Module — Laravel Scout Full-Text & Cross-Entity Search
    |--------------------------------------------------------------------------
    | Powered by Laravel Scout: database, meilisearch, algolia, typesense drivers.
    | What each account can find is narrowed by EntityAccess in the service.
    */
    Route::get('/search', [UniversalSearchController::class, 'index'])->name('search.index');
    Route::get('/api/search', [UniversalSearchController::class, 'search'])->name('api.search');
    Route::get('/api/search/driver', [UniversalSearchController::class, 'driverInfo'])->name('api.search.driver');
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
| Replaces the mock login in src/context/auth-context.tsx.
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1');
});

// POST only: as a GET, any page — an <img src="/logout"> in an email or forum
// post — could sign a resident out. POST also brings CSRF protection.
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Dashboard — rendered with Inertia + React
|--------------------------------------------------------------------------
| One route per page under src/app/dashboard/. Each maps to a component in
| resources/js/Pages/Dashboard/.
*/

/*
| Re-enter your password before a sensitive action. Named `password.confirm`
| because that is where Laravel's RequirePassword middleware sends a request
| that needs it; see App\Http\Middleware\RequirePasswordConfirmation.
*/
Route::middleware(['auth', 'active'])->prefix('dashboard')->group(function () {
    Route::get('/confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.confirm.store');
});

Route::middleware(['auth', 'active'])->prefix('dashboard')->name('dashboard.')->group(function () {

    Route::get('/', OverviewController::class)->name('index');

    // Where a hosted payment page (WiPay) sends the payer back. The provider
    // verifies what the browser brings before anything changes; the
    // controller admits only the payment's own payer.
    Route::get('/payments/{payment}/return', PaymentReturnController::class)->name('payments.return');

    // ── Profile & personal settings ──
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::post('/profile/ai-consent', [ProfileController::class, 'setAiConsent'])->name('profile.ai-consent');
    Route::post('/profile/payment-preferences', [ProfileController::class, 'updatePaymentPreferences'])->name('profile.payment-preferences');
    Route::post('/profile/theme-preset', [ProfileController::class, 'updateThemePreset'])->name('profile.theme-preset');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::middleware('role:Homeowner,Temporary Homeowner')->group(function () {
        Route::get('/deactivation', [DeactivationController::class, 'show'])->name('deactivation');
        Route::post('/deactivation', [DeactivationController::class, 'store'])->name('deactivation.store');
    });

    // ── Gate pass ──
    Route::get('/gate-pass', [GatePassController::class, 'index'])->name('gate-pass');
    Route::get('/gate-pass/token', [GatePassController::class, 'token'])->name('gate-pass.token');
    Route::post('/gate-pass/rotate', [GatePassController::class, 'rotate'])->name('gate-pass.rotate');
    Route::post('/gate-pass/scan', ScanGatePassController::class)
        ->middleware('can:scanPasses')
        ->name('gate-pass.scan');
    Route::post('/gate-pass/scans/{scan}/confirm', [GatePassController::class, 'confirmScan'])
        ->middleware(['can:scanPasses', 'throttle:60,1'])
        ->whereUuid('scan')
        ->name('gate-pass.scans.confirm');
    Route::get('/gate-scanner', [GatePassController::class, 'scanner'])
        ->middleware('can:scanPasses')
        ->name('gate-scanner');
    Route::post('/gate-pass/sync-offline-scans', [GatePassController::class, 'syncOfflineScans'])
        ->middleware('can:scanPasses')
        ->name('gate-pass.sync-offline-scans');
    // Authorization is in the controller: security for every move, a host
    // for cancelling their own guest's pass.
    Route::post('/gate-pass/{gatePass}/transition', [GatePassController::class, 'transition'])
        ->name('gate-pass.transition');
    Route::post('/gate-pass/reissue/{user}', [GatePassController::class, 'reissue'])
        ->middleware('can:manageSecurity')
        ->name('gate-pass.reissue');
    Route::post('/gate-pass/{gatePass}/revoke', [GatePassController::class, 'revoke'])
        ->middleware('can:manageSecurity')
        ->name('gate-pass.revoke');
    Route::get('/gate-pass/pdf/{gatePass}', [PdfController::class, 'downloadGatePass'])->name('gate-pass.pdf');

    // ── Digital Access Wallet (QR + NFC + Mobile Wallet Integrations) ──
    Route::get('/wallet', [DigitalAccessWalletController::class, 'index'])->name('wallet');
    Route::get('/wallet/credentials', [DigitalAccessWalletController::class, 'credentials'])->name('wallet.credentials');
    Route::get('/wallet/apple-pass/{pass}', [DigitalAccessWalletController::class, 'downloadApplePass'])->name('wallet.apple-pass');
    Route::get('/wallet/google-pass/{pass}', [DigitalAccessWalletController::class, 'googlePassPayload'])->name('wallet.google-pass');
    Route::get('/wallet/samsung-pass/{pass}', [DigitalAccessWalletController::class, 'samsungPassPayload'])->name('wallet.samsung-pass');
    Route::post('/wallet/report-lost', [DigitalAccessWalletController::class, 'reportLost'])->name('wallet.report-lost');

    // ── Access Risk Engine & Governance (Suspicious Detection, Expirations, Workflows, Leases, Timelines) ──
    Route::get('/access-governance', [AccessGovernanceController::class, 'index'])->middleware('can:manageSecurity')->name('access-governance');
    Route::post('/access-governance/approvals/{id}/approve', [AccessGovernanceController::class, 'approveRequest'])->middleware('can:manageUsers')->name('access-governance.approve');
    Route::post('/access-governance/approvals/{id}/reject', [AccessGovernanceController::class, 'rejectRequest'])->middleware('can:manageUsers')->name('access-governance.reject');
    Route::post('/access-governance/incidents/{id}/lockdown', [AccessGovernanceController::class, 'lockdownIncident'])->middleware('can:manageSecurity')->name('access-governance.lockdown');
    Route::post('/access-governance/incidents/{id}/resolve', [AccessGovernanceController::class, 'resolveIncident'])->middleware('can:manageSecurity')->name('access-governance.resolve');
    Route::get('/access-governance/timeline/{pass_id}', [AccessGovernanceController::class, 'getPassTimeline'])->middleware('can:manageSecurity')->name('access-governance.timeline');
    Route::post('/access-governance/passes/expire-sweep', [AccessGovernanceController::class, 'sweepExpiredPasses'])->middleware('can:manageUsers')->name('access-governance.sweep');
    Route::post('/access-governance/documents/audit', [AccessGovernanceController::class, 'auditDocuments'])->middleware('can:manageUsers')->name('access-governance.audit-docs');
    Route::post('/access-governance/documents/attach', [AccessGovernanceController::class, 'attachDocument'])->middleware('can:manageUsers')->name('access-governance.attach-doc');

    // ── Delegated Access Center: My Property → Access & People ──
    Route::get('/delegation', [DelegatedAccessController::class, 'index'])->name('delegation');
    Route::post('/delegation', [DelegatedAccessController::class, 'store'])->name('delegation.store');
    Route::patch('/delegation/{delegation}/rules', [DelegatedAccessController::class, 'updateRules'])->name('delegation.rules.update');
    Route::post('/delegation/{delegation}/reissue-pass', [DelegatedAccessController::class, 'reissuePass'])->name('delegation.reissue-pass');
    Route::post('/delegation/{delegation}/emergency/activate', [DelegatedAccessController::class, 'activateEmergency'])->name('delegation.emergency.activate');
    Route::post('/delegation/{delegation}/emergency/deactivate', [DelegatedAccessController::class, 'deactivateEmergency'])->name('delegation.emergency.deactivate');
    Route::post('/delegation/{delegation}/revoke', [DelegatedAccessController::class, 'revoke'])->name('delegation.revoke');
    Route::post('/delegation/{delegation}/approve', [DelegatedAccessController::class, 'approve'])->name('delegation.approve');
    Route::post('/delegation/{delegation}/reject', [DelegatedAccessController::class, 'reject'])->name('delegation.reject');
    Route::post('/delegation/accept/{token}', [DelegatedAccessController::class, 'accept'])->middleware('throttle:10,1')->name('delegation.accept');
    Route::post('/delegation/continuity-plan', [DelegatedAccessController::class, 'saveContinuityPlan'])->name('delegation.continuity-plan');
    Route::get('/delegation/{delegation}/audit-events', [DelegatedAccessController::class, 'auditEvents'])->name('delegation.audit-events');

    // ── Company-Owned Property Dashboard ──
    Route::get('/company', [CompanyDashboardController::class, 'index'])->name('company');
    Route::get('/company/{entity}', [CompanyDashboardController::class, 'show'])->name('company.show');

    // ── Dynamic Ownership & Representation / Household / Trustees Module ──
    Route::get('/ownership', [OwnershipModuleController::class, 'index'])->name('ownership');
    Route::post('/ownership/representatives', [OwnershipModuleController::class, 'storeRepresentative'])->name('ownership.representatives.store');
    Route::post('/ownership/representatives/{person}/revoke', [OwnershipModuleController::class, 'revokeRepresentative'])->name('ownership.representatives.revoke');

    // ── Enterprise Ownership Entities & Representatives ──
    Route::prefix('/ownership-entities')->name('ownership-entities.')->group(function () {
        Route::get('/{entity}', [OwnershipEntityController::class, 'show'])->name('show');
        Route::post('/', [OwnershipEntityController::class, 'store'])->name('store');
        Route::post('/{entity}/verify', [OwnershipEntityController::class, 'verify'])->name('verify');
        Route::get('/{entity}/beneficial-ownership', [OwnershipEntityController::class, 'beneficialOwnership'])->name('beneficial-ownership');
        Route::post('/{entity}/beneficial-ownership', [OwnershipEntityController::class, 'updateBeneficialOwnership'])->name('beneficial-ownership.update');
        Route::post('/{entity}/representatives', [OwnershipEntityController::class, 'addRepresentative'])->name('representatives.store');
        Route::post('/{entity}/representatives/{person}/revoke', [OwnershipEntityController::class, 'revokeRepresentative'])->name('representatives.revoke');
        Route::delete('/{entity}/representatives/{person}', [OwnershipEntityController::class, 'revokeRepresentative'])->name('representatives.destroy');
        Route::post('/{entity}/properties/{property}', [OwnershipEntityController::class, 'assignProperty'])->name('properties.assign');
    });

    // ── Family & Household Management ──
    Route::get('/household', [HouseholdController::class, 'index'])->name('household');
    Route::post('/household/members', [HouseholdController::class, 'store'])->name('household.members.store');
    Route::match(['put', 'patch'], '/household/members/{member}', [HouseholdController::class, 'update'])->name('household.members.update');
    Route::delete('/household/members/{member}', [HouseholdController::class, 'destroy'])->name('household.members.destroy');

    // ── Vehicle Management & ANPR Plate Recognition ──
    Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles');
    Route::get('/vehicles/anpr/lookup', [VehicleController::class, 'anprLookup'])->name('vehicles.anpr.lookup');
    Route::post('/vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
    Route::match(['put', 'patch'], '/vehicles/{vehicle}', [VehicleController::class, 'update'])
        ->whereNumber('vehicle')
        ->name('vehicles.update');
    Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])
        ->whereNumber('vehicle')
        ->name('vehicles.destroy');

    // ── Parking Passes (Resident, Visitor, Contractor, Temporary, Accessible, Loading Zone) ──
    Route::get('/parking', [ParkingPassController::class, 'index'])->name('parking');
    Route::post('/parking', [ParkingPassController::class, 'store'])->name('parking.store');
    Route::match(['get', 'post'], '/parking/verify', [ParkingPassController::class, 'verify'])->middleware('can:scanPasses')->name('parking.verify');

    // ── Gate Sensor & Tailgating Detection ──
    // Readings come from gate hardware (POST /api/gate-devices/sensor-events); guards review them here.
    Route::post('/gate-sensor/resolve', [GateSensorEventController::class, 'resolve'])->middleware('can:manageSecurity')->name('gate-sensor.resolve');
    Route::get('/gate-sensor/recent', [GateSensorEventController::class, 'recent'])->middleware('can:manageSecurity')->name('gate-sensor.recent');

    // ── Live "Who's On Property?" & Community Occupancy ──
    Route::get('/occupancy', [OccupancyController::class, 'index'])->name('occupancy');
    Route::get('/occupancy/unit/{unit}', [OccupancyController::class, 'unit'])->name('occupancy.unit');
    Route::get('/occupancy/hierarchy', [OccupancyController::class, 'hierarchy'])->name('occupancy.hierarchy');
    Route::get('/occupancy/export', [OccupancyController::class, 'exportRoster'])->name('occupancy.export');
    Route::post('/occupancy/muster/start', [OccupancyController::class, 'startMuster'])->name('occupancy.muster.start');
    Route::post('/occupancy/muster/status', [OccupancyController::class, 'updateMusterStatus'])->name('occupancy.muster.status');
    Route::post('/occupancy/muster/resolve', [OccupancyController::class, 'resolveMuster'])->name('occupancy.muster.resolve');
    Route::get('/occupancy/muster/export', [OccupancyController::class, 'exportMusterRoster'])->name('occupancy.muster.export');

    // ── Visitors & directory ──
    Route::middleware('can:accessEstateInformation')->group(function () {
        Route::get('/visitors', [VisitorController::class, 'index'])->name('visitors');
        Route::post('/visitors', [VisitorController::class, 'store'])->name('visitors.store');
        Route::post('/visitors/bulk', [VisitorController::class, 'storeBulk'])->name('visitors.bulk');
        Route::post('/visitors/invites', [EventRsvpController::class, 'createInvite'])->name('visitors.invites.create');
        Route::match(['put', 'patch'], '/visitors/{visitor}', [VisitorController::class, 'update'])->name('visitors.update');
        Route::post('/visitors/{visitor}/check-in', [VisitorController::class, 'checkIn'])->name('visitors.check-in');
        Route::post('/visitors/{visitor}/check-out', [VisitorController::class, 'checkOut'])->name('visitors.check-out');
        Route::post('/visitors/{visitor}/resend', [VisitorController::class, 'resendPass'])->name('visitors.resend');
        Route::post('/visitors/{visitor}/extend', [VisitorController::class, 'extendPass'])->name('visitors.extend');
        Route::delete('/visitors/{visitor}', [VisitorController::class, 'destroy'])->name('visitors.destroy');
    });

    /*
     | Admin-only, which is what the original always showed: the page rendered
     | an "Access Denied" card for everyone else and the sidebar offered it to
     | System Admin and Admin alone. The route was open, so those three
     | disagreed — a resident could load it and receive a payload the page then
     | refused to draw. The controller still redacts contact details for a
     | non-admin viewer; that is defence in depth behind this gate, not a
     | second access model.
     */
    Route::get('/directory', [DirectoryController::class, 'index'])
        ->middleware('can:manageUsers')
        ->name('directory');
    Route::post('/directory/staff', [DirectoryController::class, 'storeStaff'])->middleware('password.confirm')->name('directory.staff.store');
    Route::patch('/directory/staff/{staff}', [DirectoryController::class, 'updateStaff'])->middleware('password.confirm')->name('directory.staff.update');
    Route::delete('/directory/staff/{staff}', [DirectoryController::class, 'destroyStaff'])->middleware('password.confirm')->name('directory.staff.destroy');
    Route::post('/directory/users', [DirectoryController::class, 'storeUser'])
        ->middleware(['can:manageUsers', 'password.confirm'])
        ->name('directory.users.store');
    Route::patch('/directory/users/{user}', [DirectoryController::class, 'updateUser'])
        ->middleware(['can:manageUsers', 'password.confirm'])
        ->name('directory.users.update');
    // What each homeowner owns; HOA dues are charged per property.
    Route::post('/directory/users/{user}/properties', [DirectoryController::class, 'storeProperty'])
        ->middleware(['can:manageUsers', 'password.confirm'])
        ->name('directory.properties.store');
    Route::delete('/directory/properties/{property}', [DirectoryController::class, 'destroyProperty'])
        ->middleware(['can:manageUsers', 'password.confirm'])
        ->name('directory.properties.destroy');

    // ── Discreet distress / silent assistance ──
    // The resident's side checks residency in the controller; security's is behind manageSecurity.
    Route::get('/safety', [SafetyController::class, 'show'])->name('safety');
    Route::patch('/safety/profile', [SafetyController::class, 'updateProfile'])->name('safety.profile');
    Route::put('/safety/duress-pin', [SafetyController::class, 'setDuressPin'])
        ->middleware('password.confirm')
        ->name('safety.duress-pin');
    Route::delete('/safety/duress-pin', [SafetyController::class, 'clearDuressPin'])
        ->middleware('password.confirm')
        ->name('safety.duress-pin.clear');
    Route::post('/safety/assist', [SafetyController::class, 'requestAssistance'])
        ->middleware('throttle:10,1')
        ->name('safety.assist');
    Route::get('/safety/credential', [SafetyController::class, 'credentialSafety'])->name('safety.credential');
    Route::post('/safety/account-credential/assist', [SafetyController::class, 'requestAccountCredentialAssistance'])
        ->middleware('throttle:10,1')
        ->name('safety.account-credential.assist');
    Route::post('/safety/hold', [SafetyController::class, 'hold'])
        ->middleware('throttle:10,1')
        ->name('safety.hold');
    Route::middleware('can:manageSecurity')->group(function () {
        Route::get('/silent-assistance', [SilentAssistanceController::class, 'index'])->name('silent-assistance');
        Route::post('/silent-assistance/{alert}/acknowledge', [SilentAssistanceController::class, 'acknowledge'])->name('silent-assistance.acknowledge');
        Route::post('/silent-assistance/{alert}/resolve', [SilentAssistanceController::class, 'resolve'])->name('silent-assistance.resolve');
        Route::post('/silent-assistance/{alert}/escalate-security', [SilentAssistanceController::class, 'escalateToSecurity'])->name('silent-assistance.escalate-security');
        Route::post('/silent-assistance/{alert}/escalate-emergency', [SilentAssistanceController::class, 'escalateToEmergencyServices'])->name('silent-assistance.escalate-emergency');
    });

    Route::get('/renters', [RenterController::class, 'index'])->name('renters');
    Route::post('/renters', [RenterController::class, 'store'])->name('renters.store');
    Route::match(['put', 'patch'], '/renters/{renter}', [RenterController::class, 'update'])->name('renters.update');
    Route::delete('/renters/{renter}', [RenterController::class, 'destroy'])->name('renters.destroy');

    // ── Security ──
    /*
     | `manageSecurity`, which is the audience the controller and the export
     | gate already assumed. The route was open while the page rendered "Access
     | Denied" to anyone who was not an Admin — so a Security guard, who works
     | the gate this log records, was shut out of it while a resident could load
     | a payload nothing would draw.
     */
    Route::middleware('can:manageSecurity')->group(function () {
        Route::get('/access-log', [AccessLogController::class, 'index'])->name('access-log');
        Route::get('/access-log/export', [AccessLogController::class, 'export'])->middleware('password.confirm')->name('access-log.export');
    });

    /*
     | Read is open to any signed-in user, write is not.
     |
     | The sidebar has always offered Block List to residents, and the page has a
     | resident-only "Request Removal" action — but the GET was gated on
     | `manageBlocklist`, so the menu item 403'd and the feature was unreachable.
     | Residents now get a redacted list (name, status and date only; the reason,
     | photo and author are withheld by the controller).
     */
    Route::get('/block-list', [BlocklistController::class, 'index'])
        ->middleware('can:accessEstateInformation')
        ->name('block-list');

    Route::post('/block-list/{blocklistEntry}/request-removal', [BlocklistController::class, 'requestRemoval'])
        ->middleware('can:accessEstateInformation')
        ->whereNumber('blocklistEntry')
        ->name('block-list.request-removal');

    Route::middleware('can:manageBlocklist')->group(function () {
        Route::post('/block-list', [BlocklistController::class, 'store'])
            ->name('block-list.store');

        // Registered before the {blocklistEntry} PATCH: a wildcard segment would
        // otherwise capture the literal "requests" and fail model binding.
        Route::patch('/block-list/requests/{removalRequest}', [BlocklistController::class, 'reviewRemoval'])
            ->name('block-list.requests.review');

        Route::patch('/block-list/{blocklistEntry}', [BlocklistController::class, 'update'])
            ->whereNumber('blocklistEntry')
            ->name('block-list.update');
        Route::delete('/block-list/{blocklistEntry}', [BlocklistController::class, 'destroy'])
            ->whereNumber('blocklistEntry')
            ->name('block-list.destroy');
    });

    /*
     | Safety alerts. Any signed-in resident may raise one — the page is built
     | around resident reports, and the confirm/deny vote is the community
     | corroborating an unverified claim. Because an alert reaches everybody,
     | raising one is rate limited and `manageSecurity` can delete a false alarm.
     */
    Route::get('/warnings', [WarningController::class, 'index'])->name('warnings');
    Route::post('/warnings', [WarningController::class, 'store'])
        ->middleware('throttle:3,60')
        ->name('warnings.store');
    Route::post('/warnings/{warning}/respond', [WarningController::class, 'respond'])->name('warnings.respond');
    Route::delete('/warnings/{warning}', [WarningController::class, 'destroy'])
        ->middleware('can:manageSecurity')
        ->name('warnings.destroy');

    // ── Map & boundary ──
    Route::middleware('can:accessEstateInformation')->group(function () {
        Route::get('/map', [MapController::class, 'index'])->name('map');
        Route::post('/map/landmarks', [MapController::class, 'storeLandmark'])->name('map.landmarks.store');
        Route::delete('/map/landmarks/{landmark}', [MapController::class, 'destroyLandmark'])->name('map.landmarks.destroy');
        Route::post('/map/locate', [MapController::class, 'locate'])->name('map.locate');
    });

    Route::get('/map/boundary', [BoundaryController::class, 'edit'])
        ->middleware('can:viewBoundary')
        ->name('boundary');

    Route::middleware('can:manageBoundary')->group(function () {
        Route::post('/map/boundary/draft', [BoundaryController::class, 'saveDraft'])->name('boundary.draft');
        Route::post('/map/boundary/publish', [BoundaryController::class, 'publish'])->name('boundary.publish');
        Route::post('/map/boundary/replace/{boundaryConfig}', [BoundaryController::class, 'replace'])->name('boundary.replace');
        Route::delete('/map/boundary/{boundaryConfig}', [BoundaryController::class, 'destroy'])->name('boundary.destroy');
        Route::post('/map/boundary/validate', [BoundaryController::class, 'validateBoundary'])->name('boundary.validate');
        Route::get('/map/boundary/export', [BoundaryController::class, 'exportGeoJson'])->name('boundary.export');
        Route::post('/map/boundary/import', [BoundaryController::class, 'importGeoJson'])->name('boundary.import');
    });

    // ── Communications ──
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications', [NotificationController::class, 'store'])
        ->middleware('can:broadcastNotices')
        ->name('notifications.store');
    Route::post('/notifications/suggest-audience', [NotificationController::class, 'suggestAudience'])
        ->middleware('can:broadcastNotices')
        ->name('notifications.suggest-audience');
    Route::post('/notifications/broadcast', [NotificationController::class, 'broadcastEmergency'])
        ->middleware('can:broadcastNotices')
        ->name('notifications.broadcast');

    // ── Resident Emergency SOS Dispatch ──
    Route::post('/emergency/panic', [ResidentEmergencyController::class, 'panic'])
        ->middleware(['can:triggerEmergencyPanic', 'throttle:5,1'])
        ->name('emergency.panic');

    Route::get('/updates', [UpdateController::class, 'index'])
        ->middleware('can:accessCommunityLife')
        ->name('updates');
    Route::post('/updates', [UpdateController::class, 'store'])
        ->middleware('can:broadcastNotices')
        ->name('updates.store');

    Route::middleware('can:accessCommunityLife')->group(function () {
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
        Route::post('/calendar', [CalendarController::class, 'store'])->name('calendar.store');
        Route::patch('/calendar/{communityEvent}', [CalendarController::class, 'update'])->name('calendar.update');
        Route::delete('/calendar/{communityEvent}', [CalendarController::class, 'destroy'])->name('calendar.destroy');
    });

    Route::get('/guidelines', [GuidelinesController::class, 'index'])
        ->middleware('can:accessEstateInformation')
        ->name('guidelines');
    Route::middleware('can:broadcastNotices')->group(function () {
        Route::post('/guidelines', [GuidelinesController::class, 'store'])->name('guidelines.store');
        Route::patch('/guidelines/{guideline}', [GuidelinesController::class, 'update'])->name('guidelines.update');
        Route::delete('/guidelines/{guideline}', [GuidelinesController::class, 'destroy'])->name('guidelines.destroy');
    });

    Route::get('/changelog', [ChangelogController::class, 'index'])
        ->middleware('can:viewAppChangelog')
        ->name('changelog');
    Route::post('/changelog', [ChangelogController::class, 'store'])
        ->middleware('can:viewAppChangelog')
        ->name('changelog.store');

    Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback');
    Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');

    Route::get('/review-feedback', [ReviewFeedbackController::class, 'index'])
        ->middleware('can:reviewFeedback')
        ->name('review-feedback');
    Route::patch('/review-feedback/{feedback}', [ReviewFeedbackController::class, 'update'])
        ->middleware('can:reviewFeedback')
        ->name('review-feedback.update');

    // ── Community life ──
    Route::get('/deals', [DealsController::class, 'index'])
        ->middleware('can:accessEstateInformation')
        ->name('deals');
    Route::post('/deals/businesses', [DealsController::class, 'storeBusiness'])
        ->middleware('can:manageUsers')
        ->name('deals.businesses.store');
    Route::delete('/deals/businesses/{business}', [DealsController::class, 'destroyBusiness'])
        ->middleware('can:manageUsers')
        ->name('deals.businesses.destroy');
    Route::post('/deals/food-apps', [DealsController::class, 'storeFoodApp'])
        ->middleware('can:manageUsers')
        ->name('deals.food-apps.store');
    Route::delete('/deals/food-apps/{foodApp}', [DealsController::class, 'destroyFoodApp'])
        ->middleware('can:manageUsers')
        ->name('deals.food-apps.destroy');
    Route::post('/deals/vouchers', [DealsController::class, 'storeVoucher'])
        ->middleware('can:manageUsers')
        ->name('deals.vouchers.store');
    Route::delete('/deals/vouchers/{voucher}', [DealsController::class, 'destroyVoucher'])
        ->middleware('can:manageUsers')
        ->name('deals.vouchers.destroy');

    Route::middleware('can:accessCommunityLife')->group(function () {
        Route::get('/fundraising', [FundraisingController::class, 'index'])->name('fundraising');
        Route::post('/fundraising', [FundraisingController::class, 'store'])
            ->middleware('can:manageFundraisers')
            ->name('fundraising.store');
        Route::post('/fundraising/{fundraiser}/donate', [FundraisingController::class, 'donate'])->name('fundraising.donate');
        Route::get('/fundraising/{fundraiser}/donate/stripe-success', [FundraisingController::class, 'donationStripeSuccess'])->name('fundraising.donate.stripe.success');
        Route::get('/fundraising/{fundraiser}/donate/stripe-cancel', [FundraisingController::class, 'donationStripeCancel'])->name('fundraising.donate.stripe.cancel');
        // Backs the card's "Enable Now", which had no handler and no endpoint.
        Route::patch('/fundraising/{fundraiser}', [FundraisingController::class, 'update'])
            ->middleware('can:manageFundraisers')
            ->name('fundraising.update');
    });

    /*
     | Payments are for administrators, Homeowners and Temporary Homeowners.
     |
     | The whole area was previously ungated, so Security and Staff could reach
     | it by URL — and `index()` calls Wallet::firstOrCreate(), so simply
     | loading the page created a wallet against an account the estate never
     | bills. The sidebar hid the link from them, which hid the entry point
     | without closing it.
     |
     | The two `can:manageBilling` routes inside stay administrator-only: those
     | change the estate's rates and settle other households' invoices.
     */
    Route::middleware('can:accessBilling')->group(function () {
        Route::get('/billing', [BillingController::class, 'index'])->name('billing');
        Route::post('/billing/pay', [BillingController::class, 'pay'])->name('billing.pay');
        Route::post('/billing/card-pay', [BillingController::class, 'processCardPayment'])->name('billing.card-pay');
        // Starts a Created payment and returns its slip. Nothing reaches the
        // ledger or the office queue from here; the limit keeps it that cheap.
        Route::post('/billing/transactions/initiate', [BillingController::class, 'initiatePayment'])
            ->middleware('throttle:30,1')
            ->name('billing.transactions.initiate');
        Route::match(['post', 'patch'], '/billing/autopay', [BillingController::class, 'updateAutoPay'])->name('billing.autopay');
        Route::post('/billing/payment-links', [BillingController::class, 'storePaymentLink'])->name('billing.payment-links.store');
        Route::post('/billing/wallet/topup', [BillingController::class, 'topUpWallet'])->name('billing.wallet.topup');
        Route::post('/billing/portal', [BillingController::class, 'customerPortal'])->name('billing.portal');
        Route::post('/billing/prorate', [BillingController::class, 'calculateProration'])->name('billing.prorate');
        /*
         | The master ledger is every household's name, lot, amount and notes
         | in one CSV. It sat on `accessBilling`, so widening that gate to
         | residents handed the estate's finances to any homeowner.
         */
        Route::middleware('can:manageBilling')->group(function () {
            Route::get('/billing/export-transactions', [BillingController::class, 'exportTransactions'])->middleware('password.confirm')->name('billing.transactions.export');
        });
        Route::patch('/billing/settings', [BillingController::class, 'updateSettings'])
            ->middleware(['can:manageBilling', 'password.confirm'])
            ->name('billing.settings.update');
        Route::post('/billing/invoices/{invoice}/pay', [BillingController::class, 'markPaid'])
            ->middleware('can:manageBilling')
            ->name('billing.invoices.pay');
        Route::post('/billing/invoices/{invoice}/remind', [BillingController::class, 'sendReminder'])
            ->middleware('can:manageBilling')
            ->name('billing.invoices.remind');
        Route::get('/billing/invoices/{invoice}/pdf', [PdfController::class, 'downloadInvoice'])->name('billing.invoice.pdf');
        Route::post('/billing/invoices/{invoice}/stripe-checkout', [StripeCheckoutController::class, 'checkout'])->name('billing.stripe.checkout');
        Route::get('/billing/invoices/{invoice}/stripe-success', [StripeCheckoutController::class, 'success'])->name('billing.stripe.success');
        Route::get('/billing/invoices/{invoice}/stripe-cancel', [StripeCheckoutController::class, 'cancel'])->name('billing.stripe.cancel');
        Route::middleware('can:manageBilling')->group(function () {
            Route::post('/billing/channels/{channel}/validate', [BillingController::class, 'validateChannel'])->name('billing.channels.validate');
            Route::post('/billing/channels/{channel}/toggle', [BillingController::class, 'toggleChannel'])->name('billing.channels.toggle');
            Route::post('/billing/payment-plans', [BillingController::class, 'storePaymentPlan'])->name('billing.payment-plans.store');
            Route::post('/billing/reconciliations', [BillingController::class, 'storeReconciliation'])->name('billing.reconciliations.store');
            // Office payments: one administrator logs receipt; verification is
            // part of storeReconciliation, by a different administrator.
            Route::post('/billing/payments/{payment}/receive', [BillingController::class, 'receivePayment'])->name('billing.payments.receive');
            // In-person card readers: registered and confirmed with the
            // provider, driven by staff; payments settle by its webhook.
            Route::post('/billing/terminals', [BillingController::class, 'registerTerminal'])->name('billing.terminals.store');
            Route::post('/billing/terminals/{terminal}/retire', [BillingController::class, 'retireTerminal'])->name('billing.terminals.retire');
            Route::post('/billing/terminals/charge', [BillingController::class, 'chargeOnTerminal'])->name('billing.terminals.charge');
            Route::post('/billing/terminals/payments/{payment}/retry', [BillingController::class, 'retryOnTerminal'])->name('billing.terminals.retry');
            Route::post('/billing/terminals/payments/{payment}/cancel', [BillingController::class, 'cancelOnTerminal'])->name('billing.terminals.cancel');
            Route::post('/billing/payments/{payment}/reject', [BillingController::class, 'rejectPayment'])->name('billing.payments.reject');
        });
        Route::get('/billing/transactions/{transaction}/receipt', [BillingController::class, 'downloadReceipt'])->name('billing.transactions.receipt');
        Route::post('/billing/transactions/{transaction}/refund', [StripeCheckoutController::class, 'refund'])
            ->middleware(['can:manageBilling', 'password.confirm'])
            ->name('billing.transactions.refund');
    });

    Route::middleware('can:accessCommunityLife')->group(function () {
        Route::get('/fundraising/donations/{donation}/receipt', [FundraisingController::class, 'receipt'])->name('fundraising.donation.receipt');
        Route::post('/fundraising/{fundraiser}/updates', [FundraisingController::class, 'addUpdate'])
            ->middleware('can:manageFundraisers')
            ->name('fundraising.updates.store');
        Route::post('/fundraising/donations/{donation}/refund', [FundraisingController::class, 'refund'])
            ->middleware(['can:manageFundraisers', 'password.confirm'])
            ->name('fundraising.donations.refund');
        Route::get('/fundraising/export/donations', [FundraisingController::class, 'exportDonations'])
            ->middleware(['can:manageFundraisers', 'password.confirm'])
            ->name('fundraising.donations.export');
    });
});
