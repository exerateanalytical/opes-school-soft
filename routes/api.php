<?php

use App\Modules\Assessment\Http\Api\PublishedResultsController;
use App\Modules\Fees\Http\Api\InvoicesController;
use App\Modules\Guardians\Http\Api\AcademicsController;
use App\Modules\Guardians\Http\Api\ChildrenController;
use App\Modules\Guardians\Http\Api\CommunicationController;
use App\Modules\Guardians\Http\Api\DocumentsController;
use App\Modules\Guardians\Http\Api\EngagementController;
use App\Modules\Guardians\Http\Api\FeesController;
use App\Modules\Guardians\Http\Api\MeController;
use App\Modules\Guardians\Http\Api\ProfileController;
use App\Modules\Guardians\Http\Api\SearchController;
use App\Modules\Identity\Http\Api\GuardianAuthController;
use App\Modules\Fees\Http\Api\PaymentsController;
use App\Modules\Students\Http\Api\EnrollmentsController;
use App\Modules\Students\Http\Api\StudentsController;
use Illuminate\Support\Facades\Route;

/*
 * Read-only v1 API, docs/plans/phase-12-13.md 12.4 / 00-core 6.1.
 *
 * Every route in this file runs inside the `api` middleware group
 * (bootstrap/app.php), which applies `throttle:api` - the named 60/min
 * limiter in AppServiceProvider - before anything else.
 *
 * Deny-by-default, twice over, on every route:
 *
 *   auth:sanctum   who is calling  - a personal access token (or a
 *                  first-party session; Sanctum accepts both).
 *   can:X          may the USER see this - the same permission the web
 *                  screen for the same data requires. Hiding a nav link is
 *                  presentation, never a control (00-core 6.2); the same
 *                  applies to an API route.
 *   abilities:X    was the TOKEN issued for this - abilities are Permission
 *                  enum values chosen at token creation (/users/{user}/tokens),
 *                  so an integration holding a fees-only token cannot read
 *                  students even though its owner could. Session callers
 *                  carry Sanctum's TransientToken, which grants every
 *                  ability; the `can:` gate remains their real control.
 *
 * GET only. The v1 surface is read-only by design: writes stay behind the
 * module Actions and their Livewire adapters until a write API is a
 * deliberate, spec'd decision.
 *
 * Every route added here MUST be documented in docs/api/openapi.yaml -
 * tests/Feature/Api/OpenApiCoverageTest.php fails the build otherwise, in
 * both directions (undocumented route, or documented-but-gone path).
 */
/*
 * ---------------------------------------------------------------------------
 * Guardian mobile surface (docs/specs/2026-08-11-guardian-mobile-api-v1.md).
 * ---------------------------------------------------------------------------
 *
 * A SEPARATE surface from the integration routes below, and deliberately so.
 * The routes below serve a school's back-office integrations: staff-issued
 * tokens, module-wide permissions, whole-school data. These serve one parent's
 * own children on their own phone, and carry a fourth gate the integration
 * routes have no need of - GuardianScopeMatrix, per child, per link
 * (07-students 7.5).
 *
 * The gates, outermost first:
 *
 *   throttle:auth-mobile  5/min per IP+identifier on the unauthenticated door.
 *   auth:sanctum          the device token.
 *   abilities:portal.read the TOKEN's scope. A staff integration token holding
 *                         students.view answers no here, so it cannot borrow
 *                         this surface; a device token holds portal.read and
 *                         portal.write and nothing else, so it cannot borrow
 *                         the integration surface either. The isolation runs
 *                         both ways.
 *   can:portal.access     the USER's outer portal gate.
 *   guardian.api          an ACTIVE guardian row behind the user; fixes the
 *                         business date for the request (7.3).
 *   throttle:api-portal   120/min per token.
 *
 * Write routes swap the ability for portal.write and add `idempotency`.
 */
Route::prefix('v1/auth')->group(function (): void {
    Route::post('/token', [GuardianAuthController::class, 'token'])
        ->middleware('throttle:auth-mobile')
        ->name('api.v1.auth.token');

    Route::middleware(['auth:sanctum', 'abilities:portal.read'])->group(function (): void {
        Route::post('/refresh', [GuardianAuthController::class, 'refresh'])
            ->name('api.v1.auth.refresh');
        Route::post('/logout', [GuardianAuthController::class, 'logout'])
            ->name('api.v1.auth.logout');
        Route::post('/logout-all', [GuardianAuthController::class, 'logoutAll'])
            ->name('api.v1.auth.logout_all');
        Route::get('/devices', [GuardianAuthController::class, 'devices'])
            ->name('api.v1.auth.devices');
        Route::delete('/devices/{token}', [GuardianAuthController::class, 'forgetDevice'])
            ->whereNumber('token')
            ->name('api.v1.auth.devices.forget');
    });
});

Route::middleware([
    'auth:sanctum',
    'abilities:portal.read',
    'can:portal.access',
    'guardian.api',
    'throttle:api-portal',
])->prefix('v1/me')->group(function (): void {
    Route::get('/', [MeController::class, 'show'])->name('api.v1.me.show');
    Route::get('/children', [MeController::class, 'children'])->name('api.v1.me.children');
    Route::get('/dashboard', [MeController::class, 'dashboard'])->name('api.v1.me.dashboard');

    Route::get('/children/{student}', [ChildrenController::class, 'show'])
        ->whereNumber('student')->name('api.v1.me.children.show');
    Route::get('/children/{student}/guardians', [ChildrenController::class, 'guardians'])
        ->whereNumber('student')->name('api.v1.me.children.guardians');
    Route::get('/children/{student}/medical', [ChildrenController::class, 'medical'])
        ->whereNumber('student')->name('api.v1.me.children.medical');

    // Slice C - academics. Each of these carries a second, query-level
    // conjunct the matrix explicitly does not own: publication state for
    // results, `visibility = 'guardian'` for discipline, an applied decision
    // for promotion.
    Route::get('/children/{student}/results', [AcademicsController::class, 'results'])
        ->whereNumber('student')->name('api.v1.me.children.results');
    Route::get('/children/{student}/attendance', [AcademicsController::class, 'attendance'])
        ->whereNumber('student')->name('api.v1.me.children.attendance');
    Route::get('/children/{student}/discipline', [AcademicsController::class, 'discipline'])
        ->whereNumber('student')->name('api.v1.me.children.discipline');
    Route::get('/children/{student}/timetable', [AcademicsController::class, 'timetable'])
        ->whereNumber('student')->name('api.v1.me.children.timetable');

    // Slice D - money and paperwork. Two grants of different width: the wide
    // `receives_invoices OR is_fee_payer` one (rows 13-15, 17) and the row-16
    // floor every valid link carries. /payments is deliberately NOT
    // child-scoped: row 16 is granted on any valid link without naming a child.
    Route::get('/children/{student}/fees', [FeesController::class, 'show'])
        ->whereNumber('student')->name('api.v1.me.children.fees');
    Route::get('/children/{student}/invoices/{invoice}', [FeesController::class, 'invoice'])
        ->whereNumber('student')->whereNumber('invoice')->name('api.v1.me.children.invoices.show');
    Route::get('/children/{student}/receipts/{payment}', [FeesController::class, 'receipt'])
        ->whereNumber('student')->whereNumber('payment')->name('api.v1.me.children.receipts.show');
    Route::get('/payments', [FeesController::class, 'payments'])
        ->name('api.v1.me.payments');

    Route::get('/children/{student}/documents', [DocumentsController::class, 'index'])
        ->whereNumber('student')->name('api.v1.me.children.documents');
    Route::get('/children/{student}/documents/{kind}/{document}/download', [DocumentsController::class, 'download'])
        ->whereNumber('student')->whereIn('kind', ['school', 'supplied'])->whereNumber('document')
        ->name('api.v1.me.children.documents.download');

    // Slice E reads. Announcements are matrix territory (row 26); threads and
    // notifications are NOT - a thread is authorized by participation and a
    // notification by ownership of the row. See CommunicationController.
    Route::get('/announcements', [CommunicationController::class, 'announcements'])
        ->name('api.v1.me.announcements');
    Route::get('/notifications', [CommunicationController::class, 'notifications'])
        ->name('api.v1.me.notifications');
    Route::get('/threads', [CommunicationController::class, 'threads'])
        ->name('api.v1.me.threads');
    Route::get('/threads/{thread}/messages', [CommunicationController::class, 'messages'])
        ->whereNumber('thread')->name('api.v1.me.threads.messages');

    // Slice F. Not one index filtered afterwards - a per-child, per-capability
    // walk, because a result count or a snippet discloses a record's existence
    // just as surely as the record does. See SearchController.
    Route::get('/search', [SearchController::class, 'search'])->name('api.v1.me.search');
});

/*
 * The write half of the guardian surface. Same four gates, but the ability is
 * `portal.write` - a token issued for reading cannot post - and every POST
 * carries `idempotency`, because a flaky mobile network must not double-send a
 * message, double-book a meeting or double-charge a card.
 */
Route::middleware([
    'auth:sanctum',
    'abilities:portal.write',
    'can:portal.access',
    'guardian.api',
    'throttle:api-portal',
])->prefix('v1/me')->group(function (): void {
    Route::post('/children/{student}/payments', [FeesController::class, 'initiatePayment'])
        ->whereNumber('student')->middleware('idempotency')
        ->name('api.v1.me.children.payments.initiate');

    // Slice E writes. `idempotency` is on the ones a double-tap would make a
    // visible mess of: a duplicate message in a teacher's inbox, a duplicate
    // meeting on the office's calendar, a second evidentiary signature.
    Route::post('/notifications/{notification}/read', [CommunicationController::class, 'readNotification'])
        ->whereNumber('notification')->name('api.v1.me.notifications.read');
    Route::post('/notifications/read-all', [CommunicationController::class, 'readAllNotifications'])
        ->name('api.v1.me.notifications.read_all');

    Route::post('/threads/{thread}/messages', [CommunicationController::class, 'sendMessage'])
        ->whereNumber('thread')->middleware('idempotency')
        ->name('api.v1.me.threads.messages.send');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('api.v1.me.profile.update');

    Route::post('/devices/push', [ProfileController::class, 'registerPush'])
        ->name('api.v1.me.devices.push.register');
    Route::delete('/devices/push', [ProfileController::class, 'unregisterPush'])
        ->name('api.v1.me.devices.push.unregister');

    Route::post('/children/{student}/meetings', [EngagementController::class, 'requestMeeting'])
        ->whereNumber('student')->middleware('idempotency')
        ->name('api.v1.me.children.meetings.request');
    Route::post('/children/{student}/sanctions/{sanction}/ack', [EngagementController::class, 'acknowledgeSanction'])
        ->whereNumber('student')->whereNumber('sanction')->middleware('idempotency')
        ->name('api.v1.me.children.sanctions.ack');
});

Route::middleware('auth:sanctum')->prefix('v1')->group(function (): void {
    Route::get('/students', [StudentsController::class, 'index'])
        ->middleware(['can:students.view', 'abilities:students.view'])
        ->name('api.v1.students.index');

    Route::get('/students/{student}', [StudentsController::class, 'show'])
        ->middleware(['can:students.view', 'abilities:students.view'])
        ->whereNumber('student')
        ->name('api.v1.students.show');

    Route::get('/enrollments', [EnrollmentsController::class, 'index'])
        ->middleware(['can:students.view', 'abilities:students.view'])
        ->name('api.v1.enrollments.index');

    Route::get('/invoices', [InvoicesController::class, 'index'])
        ->middleware(['can:fee.view', 'abilities:fee.view'])
        ->name('api.v1.invoices.index');

    Route::get('/invoices/{invoice}', [InvoicesController::class, 'show'])
        ->middleware(['can:fee.view', 'abilities:fee.view'])
        ->whereNumber('invoice')
        ->name('api.v1.invoices.show');

    Route::get('/payments', [PaymentsController::class, 'index'])
        ->middleware(['can:fee.view', 'abilities:fee.view'])
        ->name('api.v1.payments.index');

    Route::get('/payments/{payment}', [PaymentsController::class, 'show'])
        ->middleware(['can:fee.view', 'abilities:fee.view'])
        ->whereNumber('payment')
        ->name('api.v1.payments.show');

    Route::get('/results', [PublishedResultsController::class, 'index'])
        ->middleware(['can:academics.view', 'abilities:academics.view'])
        ->name('api.v1.results.index');
});
