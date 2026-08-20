@props([
    'label' => '',
    'value' => null,
    'sub' => null,      // the reference's second line: "All Students", "51.53% of total"
    'delta' => null,
    'href' => null,
    'icon' => null,
    'iconBg' => null,   // legacy escape hatch; `tone` is the supported way now
    'tone' => null,     // green | blue | pink | amber | purple; null derives from $iconBg
    'spark' => null,    // list<int|float> - drawn as the reference's mini trend line
    'trend' => null,    // 'up' | 'down' | null - only when $delta traces to real data
])

@php
    // 09-ui section 3.3: a null value renders an em dash, never 0. "No fee has
    // been collected" and "the figure has not been recorded" are different
    // facts, and printing 0 for the second one is how a dashboard starts lying.
    $hasValue = $value !== null && $value !== '';
    $isLink = is_string($href) && $href !== '';

    $trend = in_array($trend, ['up', 'down'], true) ? $trend : null;
    // The legacy escape hatch recoloured the BADGE only, so 39 of the 42
    // callers left the card surface on the default mint - which is why
    // /finance/invoices shipped four identical cards carrying a green, an
    // orange, a blue and a purple badge. An explicit `tone` still wins; where
    // there is none, the legacy prop names the hue the caller already chose,
    // so the surface can be derived from it and all 42 screens repaint from
    // this one arm list. Anything unmapped keeps today's green.
    $tone = in_array($tone, ['green', 'blue', 'pink', 'amber', 'purple'], true)
        ? $tone
        : match ($iconBg) {
            'bg-badge-blue' => 'blue',
            'bg-badge-orange', 'bg-heritage-yellow' => 'amber',
            'bg-heritage-red' => 'pink',
            'bg-badge-purple' => 'purple',
            default => 'green',
        };

    /*
     * THE TONE NOW PAINTS THE DISC, NOT THE CARD.
     *
     * These cards used to be a low-saturation WASH with a matching badge -
     * five hues of tinted rectangle across a row. The reference
     * (`frontend images/super admin dashbaord.png`) draws them differently
     * and the difference is the whole character of the row: every card is
     * WHITE on the ivory ground, and the only colour is a 50px solid disc.
     *
     * The `tone` API is unchanged, so all 42 call sites keep working and keep
     * the hue they already chose - what changed is which surface that hue
     * lands on. Keeping the wash "because it is what we had" would leave
     * every list screen in the product a different design from the dashboard
     * they are reached from.
     *
     * Literal class strings so Tailwind's scanner sees every one of them.
     */
    $surface = 'bg-shell-surface border-shell-divider';

    [$badge, $sparkStroke] = match ($tone) {
        'blue' => ['bg-kpi-blue-solid', 'text-kpi-blue-solid'],
        'pink' => ['bg-kpi-pink-solid', 'text-kpi-pink-solid'],
        'amber' => ['bg-kpi-amber-solid', 'text-kpi-amber-solid'],
        'purple' => ['bg-kpi-purple-solid', 'text-kpi-purple-solid'],
        default => ['bg-kpi-green-solid', 'text-kpi-green-solid'],
    };

    /*
     * $iconBg is now a HUE HINT ONLY - it feeds the tone match above and is
     * no longer painted literally.
     *
     * It used to win outright, so a caller passing `bg-primary` or
     * `bg-badge-teal` got exactly that class on the badge. That was right
     * while the tone painted the card's wash and the badge was the caller's
     * own choice. It is wrong now that the tone paints the DISC: 39 of the 42
     * callers pass a legacy value that is not in the KPI palette, so honouring
     * it literally would leave 39 screens' discs in ad-hoc colours while the
     * dashboard's are canonical - the exact inconsistency this migration
     * exists to remove.
     *
     * No caller loses its chosen HUE: the match arms above map every legacy
     * value onto the tone that means the same thing, and KpiToneTest covers
     * every arm.
     */

    $deltaTone = match ($trend) {
        'up' => 'text-kpi-green-solid',
        'down' => 'text-heritage-red',
        default => 'text-charcoal/60',
    };

    // Sparkline: normalised to a 100x28 viewBox. Flat or single-point series
    // draw nothing rather than a misleading straight line at the baseline.
    $sparkPoints = null;

    if (is_array($spark) && count($spark) > 1) {
        $nums = array_values(array_map(static fn ($n): float => (float) $n, $spark));
        $min = min($nums);
        $max = max($nums);
        $range = $max - $min;

        if ($range > 0) {
            $step = 100 / (count($nums) - 1);
            $coords = [];

            foreach ($nums as $i => $n) {
                $x = round($i * $step, 2);
                $y = round(26 - (($n - $min) / $range) * 24, 2);
                $coords[] = $x.','.$y;
            }

            $sparkPoints = implode(' ', $coords);
        }
    }
@endphp

<div {{ $attributes->merge(['class' => 'rounded-[var(--radius-card)] border '.$surface.($isLink ? ' transition hover:shadow-md hover:-translate-y-px' : '')]) }}>
    @if ($isLink)
        <a href="{{ $href }}" class="block px-[19px] pt-[21px] pb-3">
    @else
        <div class="px-[19px] pt-[21px] pb-3">
    @endif

    <div class="flex items-start gap-3">
        @if ($icon !== null)
            {{-- The coloured circle badge from the mockups - varies per card,
                 never used on page chrome (00-core 8). --}}
            <span class="flex h-[50px] w-[50px] shrink-0 items-center justify-center rounded-full {{ $badge }} text-white">
                {!! $icon !!}
            </span>
        @endif

        <div class="min-w-0 flex-1">
            {{-- Wraps to a second line rather than truncating: "UNPAID
                 INVOIC…" and "OUTSTANDING …" are the label doing none of its
                 job, and a clipped word reads as a broken build.

                 A fixed two-line box, not just a wrapping one. A one-line
                 label used to pull its numeral 20-40px above its neighbours'
                 and the row read ragged - three zeros on /welfare/discipline
                 sat at three different heights. Reserving the box costs
                 nothing on the short labels and buys one shared baseline
                 across the row. A third line still wraps rather than
                 clipping: a truncated label is the label doing none of its
                 job. --}}
            <p class="flex min-h-[2.1em] items-start text-[13px] leading-tight text-balance text-charcoal/75">{{ $label }}</p>

            {{-- `display` is for a KPI whose headline is not a numeral - a
                 status pill, a rating, a short chip. Without it those tiles get
                 hand-rolled as a plain white div beside the tinted cards, which
                 is exactly what the dashboard's SYSTEM HEALTH tile was doing:
                 one white rectangle in a row of five, and the only thing on the
                 screen that looked unfinished. A slot costs less than the
                 duplicate. --}}
            @isset($display)
                <div class="mt-2">{{ $display }}</div>
            @else
                <p class="mt-1 text-[26px] font-bold leading-none tracking-tight text-charcoal">
                    @if ($hasValue)
                        {{ $value }}
                    @else
                        <span title="{{ __('opes.ui.no_data') }}">—</span>
                        <span class="sr-only">{{ __('opes.ui.no_data') }}</span>
                    @endif
                </p>
            @endisset

            @if ($sub !== null && $sub !== '')
                {{-- WRAPS, for the same reason the label above it does: the card is
                     ~190px wide and "Compulsory allocations" truncated to
                     "Compulsory allocati...", which is the sub-line doing
                     none of its job. Two lines is the ceiling - a third would
                     push the card taller than its neighbours. --}}
                <p class="mt-0.5 line-clamp-2 text-xs leading-tight text-balance text-charcoal/55">{{ $sub }}</p>
            @endif
        </div>

        @if ($sparkPoints !== null)
            <svg class="mt-1 hidden h-7 w-20 shrink-0 {{ $sparkStroke }} sm:block" viewBox="0 0 100 28"
                 fill="none" preserveAspectRatio="none" aria-hidden="true">
                <polyline points="{{ $sparkPoints }}" stroke="currentColor" stroke-width="2.5"
                          stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        @endif
    </div>

    @if ($delta !== null && $delta !== '')
        <p class="mt-2.5 flex items-center gap-1 border-t border-charcoal/5 pt-2 text-xs font-medium {{ $deltaTone }}">
            @if ($trend === 'up')
                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M9 6h9v9"/></svg>
            @elseif ($trend === 'down')
                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M15 18H6V9"/></svg>
            @endif
            {{ $delta }}
        </p>
    @endif

    {{ $slot }}

    @if ($isLink)
        </a>
    @else
        </div>
    @endif
</div>
