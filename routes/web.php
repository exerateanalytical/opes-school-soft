<?php

use App\Modules\Identity\Livewire\Auth\Login;
use App\Modules\Operations\Http\HealthController;
use App\Modules\Operations\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
});

Route::post('/logout', function () {
    auth()->logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    /*
     * The operator's UI language, not the school's document language - see
     * Identity\Http\Middleware\SetLocale. Only `en` and `fr` are accepted; a
     * rejected value comes back as a validation error rather than silently
     * leaving the interface as it was.
     */
    Route::post('/locale', function (\Illuminate\Http\Request $request) {
        $validated = $request->validate(['locale' => 'required|in:en,fr']);
        session(['locale' => $validated['locale']]);

        return back();
    })->name('locale.set');

    /*
     * User Management, docs/specs/09-ui.md section 8.10. The gate is real from
     * the first day on purpose: the sidebar hides this link from a user
     * without `user.view`, and hiding a link is presentation, never a control
     * (00-core 6.2). The route has to refuse on its own.
     */
    Route::get('/users', \App\Modules\Identity\Livewire\Users\Index::class)
        ->middleware('can:user.view')->name('users.index');

    Route::get('/users/create', \App\Modules\Identity\Livewire\Users\Form::class)
        ->middleware('can:user.manage')->name('users.create');

    /*
     * Academic core, docs/specs/00-core.md 9.1. Same principle as /users: the
     * sidebar hides these from a user without `academics.view`, but hiding is
     * presentation - the route refuses on its own. Settings is gated harder
     * (`academics.manage`) because it shapes the structure the rest read.
     */
    Route::get('/academics/settings', \App\Modules\Academics\Livewire\Settings\AcademicSettings::class)
        ->middleware('can:academics.manage')->name('academics.settings');

    Route::get('/classes', \App\Modules\Academics\Livewire\ClassGroups\Index::class)
        ->middleware('can:academics.view')->name('classes.index');

    Route::get('/subjects', \App\Modules\Academics\Livewire\Subjects\Index::class)
        ->middleware('can:academics.view')->name('subjects.index');

    /*
     * People, docs/specs/07-students.md. Same principle again: the sidebar
     * hides what the operator cannot reach, but hiding is presentation - each
     * route refuses on its own. Every `can:` here matches the permission its
     * nav item carries in Identity\Support\Navigation, which is that file's
     * documented contract.
     *
     * A guardian record is read by whoever may read the student it belongs to,
     * so `guardians.show` is gated on `students.view`; `guardians.manage`
     * gates writing, which happens inside the student screens.
     */
    Route::get('/students', \App\Modules\Students\Livewire\Students\Index::class)
        ->middleware('can:students.view')->name('students.index');

    Route::get('/students/{student}', \App\Modules\Students\Livewire\Students\Show::class)
        ->middleware('can:students.view')->name('students.show');

    Route::get('/guardians/{guardian}', \App\Modules\Guardians\Livewire\Guardians\Show::class)
        ->middleware('can:students.view')->name('guardians.show');

    Route::get('/admissions', \App\Modules\Admissions\Livewire\Wizard::class)
        ->middleware('can:admissions.manage')->name('admissions.index');

    /*
     * Marks entry, docs/specs/01-assessment.md 17. The single highest-traffic
     * academic screen.
     *
     * `can:marks.enter` is the OUTER gate only. 7.5 scopes entry to the
     * allocations the actor teaches or has been delegated, and the component
     * re-checks that through Mark::mayEnter() on mount and on every write -
     * T22 treats reaching an unassigned allocation as a failure even for a
     * user who holds the permission.
     */
    Route::get('/marks', \App\Modules\Assessment\Livewire\Marks\Entry::class)
        ->middleware('can:marks.enter')->name('marks.entry');

    /*
     * Ledger, docs/specs/02-accounting.md. Same principle again: the sidebar
     * hides what the operator cannot reach, but hiding is presentation - each
     * route refuses on its own. Journal-entry creation is gated harder
     * (`ledger.post`) because it writes to the books; the rest is read-only.
     */
    Route::get('/ledger/chart-of-accounts', \App\Modules\Accounting\Livewire\ChartOfAccounts\Index::class)
        ->middleware('can:ledger.view')->name('ledger.chart-of-accounts');

    Route::get('/ledger/journal-entries', \App\Modules\Accounting\Livewire\JournalEntries\Index::class)
        ->middleware('can:ledger.view')->name('ledger.journal-entries.index');

    Route::get('/ledger/journal-entries/create', \App\Modules\Accounting\Livewire\JournalEntries\Form::class)
        ->middleware('can:ledger.post')->name('ledger.journal-entries.create');

    Route::get('/ledger/trial-balance', \App\Modules\Accounting\Livewire\Reports\TrialBalance::class)
        ->middleware('can:ledger.view')->name('ledger.trial-balance');
});

/*
 * Replaces Laravel's stock health route (see bootstrap/app.php). Left
 * unauthenticated on purpose so a monitor can poll it without holding a
 * credential - which is also why HealthController redacts absolute paths out of
 * every string before it answers.
 */
Route::get('/up', HealthController::class)->name('health');
