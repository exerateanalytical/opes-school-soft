<?php

use App\Modules\Identity\Livewire\Auth\Login;
use App\Modules\Operations\Http\HealthController;
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
    /*
     * The dashboard's own component arrives in Phase 0D task 4. Until then this
     * renders the real shell, so the post-login redirect target resolves and
     * layouts/app.blade.php is exercised by a request rather than only by
     * Livewire::test(), which never renders a layout.
     */
    Route::get('/dashboard', function () {
        return view('shell.placeholder', [
            'heading' => __('opes.nav.dashboard'),
            'body' => __('opes.nav.nav_disabled_title'),
        ]);
    })->name('dashboard');

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
