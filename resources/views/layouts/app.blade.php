@php
    use App\Modules\Identity\Domain\Role;
    use App\Modules\Identity\Support\Navigation;

    /** @var \App\Modules\Identity\Models\User|null $shellUser */
    $shellUser = auth()->user();

    // Permission first, then enabled/disabled. An item the user may not see is
    // absent; an item nobody can use yet is present but inert. Conflating the
    // two would either leak the roadmap or hide the product.
    $navItems = array_values(array_filter(
        Navigation::items(),
        static fn (array $item): bool => $item['permission'] === null
            || ($shellUser !== null && $shellUser->can($item['permission']->value)),
    ));

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
</head>
<body class="min-h-screen bg-ivory font-sans text-charcoal antialiased">
{{-- The top bar and the sidebar are ONE surface in chrome green, meeting at the
     corner (00-core 8.2). The drawer state lives on the wrapper so the hamburger
     in the bar can drive the sidebar beside it. --}}
<div class="flex min-h-screen flex-col" x-data="{ nav: false }" @keydown.escape.window="nav = false">

    {{-- ── Top bar ─────────────────────────────────────────────────────── --}}
    <header class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 bg-chrome px-3 sm:px-4">
        <button type="button"
                class="-ml-1 rounded p-2 text-white/90 hover:bg-chrome-light md:hidden"
                :aria-expanded="nav ? 'true' : 'false'"
                aria-controls="opes-sidebar"
                x-on:click="nav = ! nav">
            <span class="sr-only">{{ __('opes.shell.open_menu') }}</span>
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 aria-hidden="true">
                <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <a href="/dashboard" class="flex items-center gap-2 shrink-0">
            <svg class="h-6 w-6 shrink-0 text-heritage-yellow" viewBox="0 0 24 24" fill="currentColor"
                 aria-hidden="true">
                <path d="M12 2.2l2.72 6.6 7.13.53-5.44 4.6 1.7 6.94L12 17.1l-6.11 3.77 1.7-6.94-5.44-4.6 7.13-.53L12 2.2z"/>
            </svg>
            <span class="text-lg font-semibold text-white">{{ __('opes.shell.brand') }}</span>
            <span class="hidden text-xs font-medium tracking-[0.35em] text-white/80 sm:inline">{{ __('opes.shell.brand_suffix') }}</span>
        </a>

        {{-- Disabled on purpose, and it says so. A search box that quietly
             swallows every query is worse than no search box at all. --}}
        <div class="mx-auto hidden w-full max-w-sm md:block">
            <label for="opes-search" class="sr-only">{{ __('opes.shell.search') }}</label>
            <input id="opes-search" type="search" disabled
                   placeholder="{{ __('opes.shell.search') }}"
                   title="{{ __('opes.shell.search_disabled') }}"
                   aria-disabled="true"
                   class="w-full cursor-not-allowed rounded border border-white/20 bg-chrome-light/60 px-3 py-1.5 text-sm text-white/60 placeholder:text-white/50">
        </div>

        <div class="ml-auto flex items-center gap-2 sm:gap-3">
            {{-- EN / FR. Two plain form buttons rather than a select: no
                 JavaScript, and it works on the cheap Android handsets that
                 make up most of the field fleet. --}}
            <div class="flex items-center rounded border border-white/20" role="group"
                 aria-label="{{ __('opes.shell.language') }}">
                @foreach (['en' => 'EN', 'fr' => 'FR'] as $code => $short)
                    <form method="POST" action="/locale">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $code }}">
                        <button type="submit"
                                title="{{ $code === 'en' ? __('opes.shell.switch_to_english') : __('opes.shell.switch_to_french') }}"
                                @if (app()->getLocale() === $code) aria-current="true" @endif
                                class="px-2 py-1 text-xs font-semibold {{ app()->getLocale() === $code ? 'bg-heritage-yellow text-charcoal' : 'text-white/80 hover:bg-chrome-light' }}">
                            {{ $short }}
                        </button>
                    </form>
                @endforeach
            </div>

            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button"
                        class="flex items-center gap-2 rounded px-2 py-1 text-sm text-white hover:bg-chrome-light"
                        :aria-expanded="open ? 'true' : 'false'"
                        x-on:click="open = ! open">
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-chrome-light text-xs font-semibold uppercase">
                        {{ mb_substr((string) ($shellUser->name ?? '?'), 0, 1) }}
                    </span>
                    <span class="hidden max-w-40 truncate sm:inline">{{ $shellUser->name ?? '' }}</span>
                </button>

                <div x-show="open" x-cloak x-transition.opacity
                     class="absolute right-0 z-40 mt-1 w-56 rounded border border-sand bg-white py-1 shadow-lg">
                    <p class="px-3 py-2 text-xs text-charcoal/60">
                        {{ __('opes.shell.signed_in_as') }}
                        <span class="block truncate text-sm font-medium text-charcoal">{{ $shellUser->name ?? '' }}</span>
                    </p>
                    <form method="POST" action="/logout">
                        @csrf
                        <button type="submit"
                                class="w-full px-3 py-2 text-left text-sm text-charcoal hover:bg-sand">
                            {{ __('opes.shell.sign_out') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <div class="flex min-h-0 flex-1">
        {{-- ── Sidebar ─────────────────────────────────────────────────── --}}
        <div x-show="nav" x-cloak class="fixed inset-0 z-20 bg-charcoal/50 md:hidden"
             x-on:click="nav = false" aria-hidden="true"></div>

        <nav id="opes-sidebar" aria-label="{{ __('opes.shell.primary_navigation') }}"
             class="fixed inset-y-0 left-0 z-20 hidden w-60 shrink-0 overflow-y-auto bg-chrome pt-14 pb-4 md:static md:z-auto md:block md:pt-4"
             :class="{ 'hidden': ! nav }">
            <ul class="space-y-0.5 py-2">
                @foreach ($navItems as $item)
                    @php
                        $label = __('opes.nav.'.$item['key']);
                        $isActive = $item['enabled'] && $item['route'] === $currentPath;
                    @endphp
                    <li>
                        @if ($item['enabled'] && is_string($item['route']))
                            <a href="{{ $item['route'] }}"
                               @if ($isActive) aria-current="page" @endif
                               class="flex items-center border-l-4 px-3 py-2 text-sm {{ $isActive
                                   ? 'border-heritage-yellow bg-chrome-light font-semibold text-white'
                                   : 'border-transparent text-white/85 hover:bg-chrome-light hover:text-white' }}">
                                {{ $label }}
                            </a>
                        @else
                            <span aria-disabled="true"
                                  title="{{ __('opes.nav.nav_disabled_title') }}"
                                  class="flex cursor-not-allowed items-center border-l-4 border-transparent px-3 py-2 text-sm text-white/40">
                                {{ $label }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        {{-- ── Main ────────────────────────────────────────────────────── --}}
        <main class="min-w-0 flex-1 overflow-x-hidden bg-ivory px-4 py-6 sm:px-6">
            {{ $slot }}
        </main>
    </div>

    {{-- ── Status strip ────────────────────────────────────────────────── --}}
    <footer class="shrink-0 border-t border-sand bg-sand/70 px-4 py-1.5 text-xs text-charcoal/70"
            aria-label="{{ __('opes.shell.status_strip') }}">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span>{{ __('opes.shell.user') }}: {{ $shellUser->name ?? '' }}</span>
            <span aria-hidden="true">|</span>
            <span>{{ __('opes.shell.role') }}: {{ $shellRoleLabel }}</span>
            <span aria-hidden="true">|</span>
            <span>v{{ $appVersion }}</span>
            <span aria-hidden="true">|</span>
            <span>{{ __('opes.shell.connected') }}</span>
        </div>
    </footer>
</div>

@livewireScripts
</body>
</html>
