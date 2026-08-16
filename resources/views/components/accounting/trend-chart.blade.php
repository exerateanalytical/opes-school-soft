{{--
    The monthly-collection-trend SVG, extracted so FinanceDashboard and
    AccountingDashboard render byte-identical charts from one markup, not
    two copies that can drift. Geometry is pre-computed by
    MonthlyCollectionTrend::chartGeometry() - this file only draws.
--}}
@props(['series', 'geometry', 'endLabel'])

@php
    use App\Support\Money\Money;
@endphp

<svg viewBox="0 0 660 220" role="img" class="h-auto w-full min-w-[560px]"
     aria-labelledby="fd-trend-title fd-trend-desc">
    <title id="fd-trend-title">Monthly collection trend</title>
    <desc id="fd-trend-desc">Cleared receipts per month over the twelve months ending {{ $endLabel }}.</desc>

    <line x1="30" y1="170" x2="630" y2="170" stroke="var(--color-border-primary)" stroke-width="1"/>
    <line x1="30" y1="20" x2="30" y2="170" stroke="var(--color-border-primary)" stroke-width="1"/>

    <path d="{{ $geometry['area'] }}" fill="var(--color-primary)" fill-opacity="0.10"/>
    <path d="{{ $geometry['path'] }}" fill="none" stroke="var(--color-primary)" stroke-width="2.5"
          stroke-linejoin="round" stroke-linecap="round"/>

    @foreach ($geometry['points'] as $point)
        <circle cx="{{ round($point['x'], 2) }}" cy="{{ round($point['y'], 2) }}" r="3" fill="var(--color-primary)">
            <title>{{ $point['label'] }}: {{ Money::of($point['amount'])->format() }}</title>
        </circle>
        <text x="{{ round($point['x'], 2) }}" y="188" text-anchor="middle"
              font-size="9" fill="var(--color-text-muted)">{{ $point['label'] }}</text>
    @endforeach

    <text x="30" y="14" font-size="9" fill="var(--color-text-muted)">{{ Money::of($geometry['max'])->format(false) }}</text>
</svg>
