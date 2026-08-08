<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
