<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Phase 12 (docs/plans/phase-12-13.md 12.4): the read-only v1 API.
        // Registering the file here puts every route in it behind the `api`
        // middleware group, whose throttle reads the named `api` rate limiter
        // defined in AppServiceProvider (60/min - 00-core 6.1 treats REST as
        // just another adapter, so it gets the same deny-by-default gates as
        // the web routes plus a budget the web adapter's session flow never
        // needed).
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // `health: '/up'` is deliberately ABSENT. Laravel's stock /up answers
        // "PHP is running", which is the least interesting question about this
        // installation: it is green while the backups have silently stopped.
        // Operations\Http\HealthController replaces it in routes/web.php and
        // reports the eleven real checks. Two definitions of /up would have the
        // stock one win by registration order, so it has to go, not sit
        // alongside.
    )
    ->withCommands([
        __DIR__.'/../app/Modules/Identity/Console',
        __DIR__.'/../app/Modules/Operations/Console',
        __DIR__.'/../app/Modules/Accounting/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Stated rather than inferred. Laravel's `guest` middleware falls back
        // to whichever of `dashboard` or `home` happens to be registered, which
        // makes the destination of an already-signed-in visitor an accident of
        // route naming. OPES has exactly one landing screen; name it.
        $middleware->redirectUsersTo('/dashboard');
        $middleware->redirectGuestsTo('/login');

        // The operator's UI language, chosen per session. Appended to the web
        // group so it runs after StartSession - it reads session('locale'), so
        // it cannot run before the session exists.
        $middleware->appendToGroup('web', \App\Modules\Identity\Http\Middleware\SetLocale::class);

        // Opt in to API throttling (Laravel 12 leaves the api group's
        // throttle OFF until asked): every routes/api.php route now runs
        // `throttle:api`, the named 60/min limiter in AppServiceProvider.
        // Note the framework's middleware priority runs Authenticate BEFORE
        // ThrottleRequests, so an unauthenticated request is refused with
        // 401 without consuming budget - the limiter's IP-key branch exists
        // for completeness, but the budget it actually enforces is the
        // authenticated caller's 60/min.
        $middleware->throttleApi();

        // Sanctum's token-ability checks, aliased so routes/api.php can pair
        // every `can:` gate (the USER's permission) with an `abilities:` gate
        // (the TOKEN's grant). Both must pass: the permission proves the
        // owner may see the data, the ability proves this particular token
        // was issued for it. A session-authenticated first-party request
        // carries Sanctum's TransientToken, which answers yes to every
        // ability - the user's permissions remain the only gate there.
        $middleware->alias([
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
