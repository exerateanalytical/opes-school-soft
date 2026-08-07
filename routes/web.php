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

/*
 * PLACEHOLDER. The real dashboard arrives in Phase 0D task 4. It exists now
 * only so the post-login redirect target resolves, and so Laravel's `guest`
 * middleware - which prefers a route named `dashboard` - has something to
 * point an already-authenticated visitor at.
 */
Route::get('/dashboard', function () {
    return response('OPES SCHOOL');
})->middleware('auth')->name('dashboard');

/*
 * Replaces Laravel's stock health route (see bootstrap/app.php). Left
 * unauthenticated on purpose so a monitor can poll it without holding a
 * credential - which is also why HealthController redacts absolute paths out of
 * every string before it answers.
 */
Route::get('/up', HealthController::class)->name('health');
