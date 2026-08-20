@php
    use App\Modules\Identity\Domain\Role;
    use App\Modules\Identity\Support\Navigation;
    use App\Modules\SchoolProfile\Actions\ReadSetting;
    use App\Modules\SchoolProfile\Actions\ResolveSchoolLogo;
    use App\Modules\SchoolProfile\Livewire\Branding;
    use App\Support\Branding\BrandTokens;

    /** @var \App\Modules\Identity\Models\User|null $shellUser */
    $shellUser = auth()->user();

    // The school's brand palette (/settings/branding), emitted as an
    // UNLAYERED :root override after the compiled stylesheet.
    //
    // Unlayered is load-bearing: Tailwind 4 compiles utilities into
    // @layer utilities, and unlayered CSS outranks every layered rule
    // regardless of specificity. A @layer components version of this block
    // measures correctly in devtools and repaints nothing.
    //
    // ReadSetting is cached (rememberForever, invalidated on write), so
    // this costs nothing beyond the first read per deploy. Wrapped
    // defensively: a hand-edited or stale palette must never take every
    // page in the app down over a cosmetic preference.
    $brandVariables = [];
    $faviconPath = '';

    try {
        $reader = app(ReadSetting::class);

        /** @var mixed $storedPalette */
        $storedPalette = $reader->handle(Branding::PALETTE_KEY, BrandTokens::DEFAULTS);

        $brandVariables = BrandTokens::fromArray(
            is_array($storedPalette) ? $storedPalette : BrandTokens::DEFAULTS
        )->toCssVariables();

        $faviconPath = (string) $reader->handle('branding.favicon_path', '');
    } catch (\Throwable) {
        $brandVariables = BrandTokens::defaults()->toCssVariables();
        $faviconPath = '';
    }

    // ONE resolver, shared with the sign-in page, the guardian portal and the
    // letterhead of newly issued documents. See ResolveSchoolLogo for the
    // precedence and for why the favicon below is NOT part of it (a favicon
    // is a 32px square, not the logo at a small size).
    $appLogoUrl = app(ResolveSchoolLogo::class)->url();

    // Only ever a relative path under branding/ on the `public` disk (the
    // uploader's own contract, mirrored by the settings validation_rule) -
    // so nothing hand-typed can become an arbitrary icon href.
    $faviconUrl = ($faviconPath !== '' && str_starts_with($faviconPath, 'branding/'))
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($faviconPath)
        : null;

    // Permission first, then enabled/disabled. An item the user may not see is
    // absent; an item nobody can use yet is present but inert. Conflating the
    // two would either leak the roadmap or hide the product.
    //
    // groupedItems() arranges those SAME items under the eighteen top-level
    // groups the reference sidebar shows. It is a presentation layer only:
    // every item keeps its route, its permission and its `built` flag, and
    // NavigationGroupsTest asserts nothing can fall out of the arrangement.
    $navGroups = Navigation::groupedItems(
        static fn ($permission): bool => $shellUser !== null && $shellUser->can($permission->value),
    );

    // The session strip in the sidebar and the year selector in the bar read
    // the SAME rows the rest of the product does - no invented school year.
    // Wrapped because the shell must not take every page down if academics
    // has not been set up yet on a fresh install.
    $activeYearName = '';
    $activeTermName = '';

    try {
        $activeYear = \App\Modules\Academics\Models\AcademicYear::query()->where('is_current', true)->first();
        $activeYearName = (string) ($activeYear->name ?? '');

        if ($activeYear !== null) {
            $activeTermName = (string) (\Illuminate\Support\Facades\DB::table('assessment_periods')
                ->where('academic_year_id', $activeYear->getKey())
                ->whereIn('type', ['term', 'trimestre'])
                ->whereDate('starts_on', '<=', now())
                ->whereDate('ends_on', '>=', now())
                ->value(app()->getLocale() === 'fr' ? 'name_fr' : 'name') ?? '');
        }
    } catch (\Throwable) {
        $activeYearName = '';
        $activeTermName = '';
    }

    // "2026 / 2027" out of "Academic Year 2026/2027" - the compact form the
    // reference puts in the top bar's year selector.
    preg_match('/(\d{4})\s*\/\s*(\d{4})/', $activeYearName, $yearParts);
    $compactYear = $yearParts === [] ? $activeYearName : $yearParts[1].' / '.$yearParts[2];

    $currentPath = '/'.ltrim(request()->path(), '/');

    $shellRoleName = $shellUser?->getRoleNames()->first();
    $shellRole = is_string($shellRoleName) ? Role::tryFrom($shellRoleName) : null;
    $shellRoleLabel = $shellRole?->label(app()->getLocale()) ?? __('opes.shell.no_role');

    $appVersion = (string) config('app.version', 'dev');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'OPES SCHOOL') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    @if ($faviconUrl !== null)
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif

    {{-- UNLAYERED on purpose - see the PHP block at the top of this file.
         Blade compiles directives BEFORE it strips comments, so a directive
         name written inside a comment is still compiled: never spell one out
         here. --}}
    <style>
        :root {
            @foreach ($brandVariables as $brandVariableName => $brandVariableValue)
            {{ $brandVariableName }}: {{ $brandVariableValue }};
            @endforeach
        }
    </style>
</head>
{{-- `opes-app` is the scope hook for the shared form-control treatment in
     app.css. It is deliberately ONLY on this layout: the auth screens and the
     guardian portal carry their own approved designs, and a platform-wide
     field restyle must not reach into them. --}}
<body class="opes-app min-h-screen bg-shell-ground font-sans text-charcoal antialiased">
{{-- The top bar is white; the sidebar is dark chrome-green running the full
     viewport height beside it (00-core 8.2 - one continuous chrome surface,
     just no longer painted the same colour top-to-bottom). The drawer state
     lives on the wrapper so the hamburger in the bar can drive the sidebar. --}}
<div class="flex min-h-screen flex-col md:flex-row" x-data="{ nav: false }" @keydown.escape.window="nav = false">

    {{-- ── Sidebar ─────────────────────────────────────────────────────── --}}
    <div x-show="nav" x-cloak class="fixed inset-0 z-20 bg-charcoal/50 md:hidden"
         x-on:click="nav = false" aria-hidden="true"></div>

    {{-- `md:sticky md:top-0 md:h-screen md:self-start`, NOT `md:static`.

         The nav lists every module the holder can reach. As a static flex
         item it grows to its content height and, being a sibling of <main> in
         a flex row, drags the whole PAGE to that height - which is where
         ~1700px of blank canvas under every screen came from, and it was
         never a dashboard bug. h-screen supplies the constraint that makes
         the inner overflow-y-auto fire; self-start stops the flex row
         stretching it back out (align-items defaults to stretch, which would
         silently undo h-screen); sticky keeps it in view while the page
         scrolls. Below md the drawer is `fixed inset-y-0` and is untouched. --}}
    <div id="opes-sidebar"
         class="fixed inset-y-0 left-0 z-20 hidden shrink-0 md:sticky md:top-0 md:z-auto md:block md:h-screen md:self-start"
         :class="{ 'hidden': ! nav, 'block': nav }">
        <x-shell.sidebar :groups="$navGroups"
                         :current-path="$currentPath"
                         :logo-url="$appLogoUrl"
                         :user="$shellUser"
                         :role-label="$shellRoleLabel"
                         :academic-year="$activeYearName"
                         :term="$activeTermName"/>
    </div>

    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
        {{-- ── Top bar ─────────────────────────────────────────────────── --}}
        {{-- ── Top bar ─────────────────────────────────────────────────── --}}
        <x-shell.topbar :user="$shellUser"
                        :role-label="$shellRoleLabel"
                        :greeting-name="$shellUser->name ?? ''"
                        :academic-year="$compactYear"
                        :campus-label="__('opes.shell.all_campuses')"
                        :unread-messages="0"/>

        {{-- ── Main ────────────────────────────────────────────────────── --}}
        {{-- The canvas is deliberately the soft surface, not white: the design
             system's desktop structure is white CARDS lifting off a #F5F7F6
             BODY. On an all-white canvas the cards have nothing to separate
             from and the page reads flat. --}}
        {{-- The canvas is the WARM ivory the reference measures at #FBFAF7,
             not the older cool #F5F7F6 sand: white cards on a warm ground is
             what makes the design read as one surface. Horizontal padding is
             the reference's own 19/18px gutters, rounded to 20. --}}
        {{-- pt-0: the header carries the whole gap above the first card
             (measured 118px from the top of the page to the card's top
             edge), so a top padding here would add to it rather than be
             it. --}}
        <main class="min-w-0 flex-1 overflow-x-hidden bg-shell-ground px-5 pt-0 pb-4">
            {{ $slot }}
        </main>

        {{-- ── Status strip ────────────────────────────────────────────── --}}
        <footer class="shrink-0 bg-chrome px-4 py-1.5 text-xs text-white/80"
                aria-label="{{ __('opes.shell.status_strip') }}">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span>{{ __('opes.shell.user') }}: {{ $shellUser->name ?? '' }}</span>
                    <span aria-hidden="true">|</span>
                    <span>{{ __('opes.shell.role') }}: {{ $shellRoleLabel }}</span>
                    <span aria-hidden="true">|</span>
                    {{-- No academic-session concept exists yet, so this is an
                         honest em-dash rather than an invented school year. --}}
                    <span>Session: —</span>
                </div>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span>V {{ $appVersion }}</span>
                    <span aria-hidden="true">|</span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-heritage-yellow" aria-hidden="true"></span>
                        {{ __('opes.shell.connected') }}
                    </span>
                    <svg class="h-3.5 w-3.5 text-white/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <ellipse cx="12" cy="5" rx="8" ry="3"/>
                        <path stroke-linecap="round" d="M4 5v6c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 11v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/>
                    </svg>
                </div>
            </div>
        </footer>
    </div>
</div>

@livewireScripts

@if ($shellUser !== null)
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.opesInitPushNotifications) {
                window.opesInitPushNotifications();
            }
        });
    </script>
@endif
</body>
</html>
