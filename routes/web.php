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
     *
     * The class_exists guards are temporary scaffolding: Laravel validates an
     * invokable route action at registration time, so naming a component that
     * is still being built would stop the whole application from booting.
     * Once the Students/Guardians/Admissions Livewire components exist the
     * guards are always true and should be removed in favour of plain ::class
     * references.
     */
    if (class_exists('App\Modules\Students\Livewire\Students\Index')) {
        Route::get('/students', 'App\Modules\Students\Livewire\Students\Index')
            ->middleware('can:students.view')->name('students.index');
    }

    if (class_exists('App\Modules\Students\Livewire\Students\Show')) {
        Route::get('/students/{student}', 'App\Modules\Students\Livewire\Students\Show')
            ->middleware('can:students.view')->name('students.show');
    }

    if (class_exists('App\Modules\Guardians\Livewire\Guardians\Show')) {
        Route::get('/guardians/{guardian}', 'App\Modules\Guardians\Livewire\Guardians\Show')
            ->middleware('can:students.view')->name('guardians.show');
    }

    if (class_exists('App\Modules\Admissions\Livewire\Wizard')) {
        Route::get('/admissions', 'App\Modules\Admissions\Livewire\Wizard')
            ->middleware('can:admissions.manage')->name('admissions.index');
    }
});

/*
 * Replaces Laravel's stock health route (see bootstrap/app.php). Left
 * unauthenticated on purpose so a monitor can poll it without holding a
 * credential - which is also why HealthController redacts absolute paths out of
 * every string before it answers.
 */
Route::get('/up', HealthController::class)->name('health');
