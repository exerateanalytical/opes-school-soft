<?php

use App\Modules\Assessment\Http\Api\PublishedResultsController;
use App\Modules\Fees\Http\Api\InvoicesController;
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
