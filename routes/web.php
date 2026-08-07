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
     *
     * The class_exists guards are temporary scaffolding: Laravel validates an
     * invokable route action at registration time, so naming a component that
     * is still being built would stop the whole application from booting.
     * Once the Academics Livewire components exist the guards are always true
     * and should be removed in favour of plain ::class references.
     */
    if (class_exists('App\Modules\Academics\Livewire\Settings\AcademicSettings')) {
        Route::get('/academics/settings', 'App\Modules\Academics\Livewire\Settings\AcademicSettings')
            ->middleware('can:academics.manage')->name('academics.settings');
    }

    if (class_exists('App\Modules\Academics\Livewire\ClassGroups\Index')) {
        Route::get('/classes', 'App\Modules\Academics\Livewire\ClassGroups\Index')
            ->middleware('can:academics.view')->name('classes.index');
    }

    if (class_exists('App\Modules\Academics\Livewire\Subjects\Index')) {
        Route::get('/subjects', 'App\Modules\Academics\Livewire\Subjects\Index')
            ->middleware('can:academics.view')->name('subjects.index');
    }
});

/*
 * Replaces Laravel's stock health route (see bootstrap/app.php). Left
 * unauthenticated on purpose so a monitor can poll it without holding a
 * credential - which is also why HealthController redacts absolute paths out of
 * every string before it answers.
 */
Route::get('/up', HealthController::class)->name('health');
