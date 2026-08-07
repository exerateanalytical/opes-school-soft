<?php

namespace App\Providers;

use App\Modules\Identity\Livewire\Users\Index as UsersIndex;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Livewire infers a component's public name from its class name, and
        // strips a trailing ".index" segment on the assumption the class
        // itself is reachable via a sibling namespace lookup (09-ui 8.10's
        // Users\Index has no such sibling). Left to the default resolver,
        // routing straight to the class - as routes/web.php does - throws
        // ComponentNotFoundException. An explicit name sidesteps the
        // stripping logic entirely (Finder::normalizeName resolves it before
        // ever reaching the ".index" special case).
        Livewire::component('users.index', UsersIndex::class);
    }
}
