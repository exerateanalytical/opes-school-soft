@props([
    'groups' => [],
    'currentPath' => '/',
    'logoUrl' => null,
    'user' => null,
    'roleLabel' => '',
    'academicYear' => '',
    'term' => '',
])

{{--
    The back-office sidebar, built to `frontend images/super admin
    dashbaord.png`.

    MEASURED, not estimated (full working in
    docs/superpowers/specs/2026-08-20-admin-dashboard-measurements.md):

      dark field            x 0..257   -> 258px
      toghu strip           x 258..269 -> 12px, on the field's RIGHT edge
      block total                         270px
      nav item pitch        34px (measured across 18 consecutive labels)
      nav label             cap 11px -> 15px, which is text-sm at this app's
                            17px root - so the reference's type scale is this
                            app's own scale, not a new one
      active pill           37px tall, x 12..246, gold

    The strip is a SIBLING of the scrolling nav column, not a border on it:
    the nav list scrolls and the strip must not scroll with it.
--}}
@php
    $isCurrent = static fn (array $item): bool => $item['enabled']
        && is_string($item['route'])
        && $item['route'] === $currentPath;
@endphp

<div class="flex h-full">
    {{-- ── The dark field ───────────────────────────────────────────────── --}}
    <div class="flex w-[258px] shrink-0 flex-col bg-shell-field">

        {{-- Crest + wordmark lockup.

             MEASURED off the reference (light-ink bands in x 88..245):
               "OPES"                      ink y 28..53, cap 26 -> 36px
               "SCHOOL MANAGEMENT SYSTEM"  ink y 62..69, cap  8 -> 11px
               "Excellence in Education"   ink y 85..93, cap  9 -> 12.5px
               text column starts          x 85, so the crest owns x 0..82

             The wordmark is a SANS bold, not a serif, and the tagline is
             gold on its own line with nothing flanking it - an earlier
             version had it set in serif at 26px between two hairlines, which
             is a different lockup, not a near-miss.

             The crest is the real artwork (public/images/opes-crest-dark.png,
             the dark-ground variant that already exists in this repo), not a
             line drawing of it. --}}
        <a href="/dashboard" wire:navigate class="flex shrink-0 items-start gap-2 px-2.5 pt-2 pb-1.5">
            @if ($logoUrl !== null)
                {{-- The school's own uploaded logo replaces the OPES mark.
                     Height-constrained with width auto: a school logo is any
                     aspect ratio at all, and a fixed square box squashes half
                     of them. --}}
                <img src="{{ $logoUrl }}" alt="{{ __('opes.branding.app_logo_alt') }}"
                     class="h-[72px] w-auto max-w-[74px] shrink-0 object-contain">
            @else
                <img src="{{ asset('images/opes-crest-dark.png') }}" alt=""
                     class="h-[72px] w-[74px] shrink-0 object-contain">
            @endif

            <span class="min-w-0 pt-[18px]">
                <span class="block truncate text-[36px] font-bold leading-none tracking-[-0.01em] text-white">
                    {{ __('opes.shell.brand') }}
                </span>
                <span class="mt-[9px] block truncate text-[11px] font-medium uppercase leading-none tracking-[0.055em] text-white">
                    {{ __('opes.shell.brand_system_line') }}
                </span>
                <span class="mt-[16px] block truncate text-[12.5px] leading-none text-shell-gold">
                    {{ __('opes.shell.tagline') }}
                </span>
            </span>
        </a>

        {{-- ── Nav ──────────────────────────────────────────────────────── --}}
        {{-- min-h-0 is load-bearing on a flex column: without it this child
             cannot shrink below its content height, overflow-y-auto never
             fires, and the user card below is pushed off the bottom of the
             viewport instead of the list scrolling. --}}
        <nav class="min-h-0 flex-1 overflow-y-auto px-3" aria-label="{{ __('opes.shell.primary_navigation') }}">
            <ul class="space-y-[1px] pb-2">
                @foreach ($groups as $group)
                    @php
                        // A single-item group whose one member is the group
                        // itself renders as a LINK, not a disclosure. The
                        // reference draws Dashboard exactly this way, and a
                        // parent that opens to reveal one child is a worse
                        // control than the link it wraps.
                        $isLeaf = count($group['items']) === 1;
                        $leafItem = $group['items'][0];
                        $groupActive = collect($group['items'])->contains($isCurrent);
                    @endphp

                    <li @if (! $isLeaf) x-data="{ open: {{ $groupActive ? 'true' : 'false' }} }" @endif>
                        @if ($isLeaf)
                            <a href="{{ $leafItem['route'] }}" wire:navigate
                               @if ($groupActive) aria-current="page" @endif
                               class="flex h-[34px] items-center gap-2 rounded-lg px-2 text-[14.5px] transition
                                      {{ $groupActive
                                          ? 'bg-linear-to-b from-[#F7DFA4] to-[#E6C273] font-semibold text-shell-field shadow-[0_1px_3px_rgba(0,0,0,0.35)]'
                                          : 'font-medium text-white/90 hover:bg-white/10' }}">
                                <x-shell.icon :name="$group['key']"
                                              class="{{ $groupActive ? 'text-shell-field' : 'text-shell-gold' }}"/>
                                <span class="min-w-0 truncate">{{ __($group['label_key']) }}</span>
                            </a>
                        @else
                            <button type="button" x-on:click="open = ! open"
                                    :aria-expanded="open ? 'true' : 'false'"
                                    class="flex h-[34px] w-full items-center gap-2 rounded-lg px-2 text-[14.5px] transition
                                           {{ $groupActive
                                               ? 'bg-white/10 font-semibold text-white'
                                               : 'font-medium text-white/90 hover:bg-white/10' }}">
                                <x-shell.icon :name="$group['key']" class="text-shell-gold"/>
                                <span class="min-w-0 flex-1 truncate text-left">{{ __($group['label_key']) }}</span>
                                <x-shell.icon name="chevron_right"
                                              class="h-3.5 w-3.5 text-white/45 transition-transform duration-150"
                                              x-bind:class="open && 'rotate-90'"/>
                            </button>

                            <ul x-show="open" x-collapse x-cloak class="space-y-[1px] pb-1 pl-[30px]">
                                @foreach ($group['items'] as $item)
                                    <li>
                                        @if ($item['enabled'] && is_string($item['route']))
                                            <a href="{{ $item['route'] }}" wire:navigate
                                               @if ($isCurrent($item)) aria-current="page" @endif
                                               @unless ($item['built'] ?? true) title="{{ __('opes.nav.nav_disabled_title') }}" @endunless
                                               class="flex min-h-[30px] items-center gap-2 rounded-md px-2.5 py-1 text-[13px] transition
                                                      {{ $isCurrent($item)
                                                          ? 'bg-shell-gold/20 font-semibold text-shell-gold'
                                                          : (($item['built'] ?? true)
                                                              ? 'text-white/70 hover:bg-white/10 hover:text-white'
                                                              : 'text-white/45 hover:bg-white/5 hover:text-white/70') }}">
                                                <span class="min-w-0 flex-1 truncate">{{ __('opes.nav.'.$item['key']) }}</span>
                                                @unless ($item['built'] ?? true)
                                                    <span class="shrink-0 rounded-full bg-shell-gold/20 px-1.5 text-[9px] font-semibold uppercase tracking-wide text-shell-gold">
                                                        {{ __('opes.placeholder.chip_short') }}
                                                    </span>
                                                @endunless
                                            </a>
                                        @else
                                            <span aria-disabled="true" title="{{ __('opes.nav.nav_disabled_title') }}"
                                                  class="flex min-h-[30px] cursor-not-allowed items-center rounded-md px-2.5 py-1 text-[13px] text-white/30">
                                                {{ __('opes.nav.'.$item['key']) }}
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{-- Per-screen quick actions. Each screen @push()es its own list;
                 a screen that pushes nothing renders no box, rather than a
                 stale or generic one. --}}
            @stack('sidebar-quick-actions')
        </nav>

        {{-- ── Signed-in identity card ──────────────────────────────────── --}}
        <div class="shrink-0 px-3 pb-2">
            <div class="flex items-center gap-2.5 rounded-xl border border-shell-gold/45 bg-white/[0.07] px-2.5 py-2">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-shell-gold/60 bg-shell-field">
                    @if ($logoUrl !== null)
                        <img src="{{ $logoUrl }}" alt="" class="h-6 w-6 object-contain">
                    @else
                        <span class="font-serif text-[13px] font-bold text-shell-gold">
                            {{ mb_substr((string) ($user->name ?? '?'), 0, 1) }}
                        </span>
                    @endif
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] font-semibold leading-tight text-white">{{ $user->name ?? '' }}</span>
                    <span class="block truncate text-[11px] leading-tight text-white/65">{{ $roleLabel }}</span>
                    <span class="mt-0.5 flex items-center gap-1 text-[10px] leading-none text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" aria-hidden="true"></span>
                        {{ __('opes.shell.online') }}
                    </span>
                </span>
            </div>
        </div>

        {{-- ── Academic session ─────────────────────────────────────────── --}}
        <div class="shrink-0 border-t border-shell-gold/35 px-4 py-2.5">
            <p class="text-[12px] font-medium leading-tight text-white/85">{{ $academicYear }}</p>
            <p class="mt-0.5 flex items-center text-[13px] leading-tight text-white">
                <span class="min-w-0 flex-1 truncate">{{ $term }}</span>
                <x-shell.icon name="chevron_down" class="h-3.5 w-3.5 text-white/50"/>
            </p>
        </div>

        {{-- The toghu band closes the column, as the reference does. --}}
        <x-toghu-band height="h-[54px]" class="shrink-0"/>
    </div>

    {{-- ── Toghu strip, 12px, on the field's right edge ─────────────────── --}}
    <x-shell.toghu-strip/>
</div>
