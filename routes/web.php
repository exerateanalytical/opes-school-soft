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
     * STUB. Phase 0D task 6 builds User Management. The gate is real from the
     * first day on purpose: the sidebar hides this link from a user without
     * `user.view`, and hiding a link is presentation, never a control
     * (00-core 6.2). The route has to refuse on its own.
     */
    Route::get('/users', function () {
        return view('shell.placeholder', [
            'heading' => __('opes.nav.users'),
            'body' => __('opes.nav.nav_disabled_title'),
        ]);
    })->middleware('can:user.view')->name('users.index');
});

/*
 * Replaces Laravel's stock health route (see bootstrap/app.php). Left
 * unauthenticated on purpose so a monitor can poll it without holding a
 * credential - which is also why HealthController redacts absolute paths out of
 * every string before it answers.
 */
Route::get('/up', HealthController::class)->name('health');
