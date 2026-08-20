@props([
    'slices' => [],       // list<array{label: string, value: int|float}>
    'centreValue' => null,
    'centreLabel' => null,
    'size' => 150,
    'thickness' => 26,
    'legend' => true,
    'stacked' => false,   // ring above the legend, for a narrow rail
])

{{--
    A donut chart with a value in the hole and a legend beside it.

    The reference screens use this shape repeatedly - students by class,
    stock by category, fees by status - so it is a component rather than a
    per-screen SVG.

    DRAWN WITH stroke-dasharray ON CIRCLES, not with arc paths. An arc path
    needs the large-arc flag computed per slice and silently draws the SHORT
    way round for anything over 180 degrees, which turns a single-category
    dataset into a thin sliver instead of a full ring. A circle with a dash
    pattern has no such case: the dash length IS the share, and one slice at
    100% draws a complete ring.

    Rotated -90deg so the first slice starts at twelve o'clock, which is where
    every one of the references starts.

    ACCESSIBILITY: the ring is aria-hidden and the legend carries the same
    figures as real text. A chart whose only reading is its geometry is
    unreadable to a screen reader, and this one always ships its numbers.

    `stacked` puts the legend UNDER the ring. Side by side needs roughly
    380px; in a 238px rail the legend was left with ~70px, its labels
    truncated to nothing, and the chart shipped as a ring beside a column of
    bare numbers with no way to tell which was which.
--}}
@php
    $clean = [];

    foreach ($slices as $slice) {
        $value = (float) ($slice['value'] ?? 0);

        // A zero slice draws nothing and would still consume a colour, so the
        // next real slice would change hue whenever an empty category
        // appeared or disappeared.
        if ($value > 0) {
            $clean[] = ['label' => (string) ($slice['label'] ?? ''), 'value' => $value];
        }
    }

    $total = array_sum(array_column($clean, 'value'));

    // Six hues, then it wraps. Matches the KPI palette so a donut and the
    // cards above it are visibly the same design system.
    $palette = [
        'var(--color-kpi-green-solid)',
        'var(--color-kpi-blue-solid)',
        'var(--color-kpi-amber-solid)',
        'var(--color-kpi-pink-solid)',
        'var(--color-kpi-purple-solid)',
        'var(--color-shell-disc)',
    ];

    $radius = ($size - $thickness) / 2;
    $circumference = 2 * M_PI * $radius;

    $segments = [];
    $offset = 0.0;

    foreach ($clean as $i => $slice) {
        $share = $total > 0 ? $slice['value'] / $total : 0;

        $segments[] = [
            'label' => $slice['label'],
            'value' => $slice['value'],
            'percent' => $share * 100,
            'colour' => $palette[$i % count($palette)],
            'dash' => $share * $circumference,
            'gap' => $circumference - ($share * $circumference),
            'offset' => -$offset * $circumference,
        ];

        $offset += $share;
    }
@endphp

<div {{ $attributes->merge(['class' => $stacked ? 'flex flex-col items-center gap-3' : 'flex items-center gap-4']) }}>
    @if ($segments === [])
        <p class="text-[13px] text-charcoal/55">{{ __('opes.ui.no_data') }}</p>
    @else
        <div class="relative shrink-0" style="width: {{ $size }}px; height: {{ $size }}px;">
            <svg viewBox="0 0 {{ $size }} {{ $size }}" class="h-full w-full -rotate-90" aria-hidden="true">
                <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $radius }}"
                        fill="none" stroke="var(--color-shell-divider)" stroke-width="{{ $thickness }}"/>
                @foreach ($segments as $segment)
                    <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $radius }}"
                            fill="none" stroke="{{ $segment['colour'] }}" stroke-width="{{ $thickness }}"
                            stroke-dasharray="{{ round($segment['dash'], 2) }} {{ round($segment['gap'], 2) }}"
                            stroke-dashoffset="{{ round($segment['offset'], 2) }}"/>
                @endforeach
            </svg>

            @if ($centreValue !== null)
                <div class="absolute inset-0 flex flex-col items-center justify-center leading-none">
                    <span class="text-[19px] font-bold text-charcoal">{{ $centreValue }}</span>
                    @if ($centreLabel !== null)
                        <span class="mt-1 text-[10px] text-charcoal/60">{{ $centreLabel }}</span>
                    @endif
                </div>
            @endif
        </div>

        @if ($legend)
            <ul class="w-full min-w-0 space-y-[7px] {{ $stacked ? '' : 'flex-1' }}">
                @foreach ($segments as $segment)
                    <li class="flex items-center gap-2 text-[12px] leading-none">
                        <span class="h-2 w-2 shrink-0 rounded-full" aria-hidden="true"
                              style="background: {{ $segment['colour'] }}"></span>
                        <span class="min-w-0 flex-1 truncate text-charcoal">{{ $segment['label'] }}</span>
                        <span class="shrink-0 tabular-nums text-charcoal/70">
                            {{ number_format($segment['value']) }} ({{ number_format($segment['percent'], 1) }}%)
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
