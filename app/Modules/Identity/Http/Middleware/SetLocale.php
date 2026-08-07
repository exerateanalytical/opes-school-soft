<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the OPERATOR'S UI LANGUAGE for the current request.
 *
 * 00-core section 18 keeps two independent language axes and they must never be
 * merged:
 *
 *  1. The operator's UI language - this middleware. A per-user, per-session
 *     preference for the chrome, the menus and the validation messages.
 *  2. The school's document language - a school-wide setting used when printing
 *     report cards, receipts and transcripts (arrives with the reporting
 *     module).
 *
 * A bursar reading the interface in English at a francophone school still has
 * to print French report cards. Driving documents off `app()->getLocale()`
 * would make the language of an official record depend on who happened to press
 * the button, so document rendering must read its own setting instead.
 */
final class SetLocale
{
    /** The only two languages OPES SCHOOL ships (00-core section 18). */
    public const SUPPORTED = ['en', 'fr'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (! is_string($locale) || ! in_array($locale, self::SUPPORTED, true)) {
            $locale = (string) config('app.locale', 'en');
        }

        // A config value outside the supported set would otherwise leak an
        // untranslated interface; fall back rather than trust it.
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
