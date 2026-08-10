@php
    use App\Support\Money\Money;

    /**
     * 02-accounting §21.3. Every chart on this page is inline SVG computed
     * from the arrays the component handed over - this app ships no JS
     * charting library and adds no build step for one.
     *
     * Palette: primary green #0F5132 for money in, heritage amber for money
     * owed but not yet due, heritage red for money overdue. Colour never
     * carries meaning alone (09-ui §10) - every slice, bar and series is
     * labelled in text as well.
     */
    $periodTabs = [
        ['value' => 'month', 'label' => 'This month'],
        ['value' => 'term', 'label' => 'This term'],
        ['value' => 'year', 'label' => $axisLabel],
        ['value' => 'custom', 'label' => 'Custom range'],
    ];

    $chartTabs = [
        ['value' => 'income-category', 'label' => 'Income by Category'],
        ['value' => 'expense-category', 'label' => 'Expense by Category'],
        ['value' => 'monthly-trend', 'label' => 'Monthly Collection Trend'],
    ];

    $seriesMax = 0;
    foreach ($chartSeries as $point) {
        $seriesMax = max($seriesMax, (int) $point['amount']);
    }

    $incomeMax = 0;
    foreach ($incomeByCategory as $point) {
        $incomeMax = max($incomeMax, (int) $point['amount']);
    }

    $treasuryTotal = 0;
    foreach ($treasury as $account) {
        $treasuryTotal += (int) $account['balance'];
    }

    // Donut geometry: one circle, three stroke-dasharray arcs. r=70 gives a
    // circumference of 2*pi*70 = 439.82.
    $donutCircumference = 439.82;
    $donutTotal = (int) $collection['total'];
    $donutSlices = [];
    if ($donutTotal > 0) {
        $offset = 0.0;
        foreach ([
            ['key' => 'collected', 'label' => 'Collected', 'colour' => '#0F5132'],
            ['key' => 'outstanding', 'label' => 'Outstanding', 'colour' => '#C9A227'],
            ['key' => 'overdue', 'label' => 'Overdue', 'colour' => '#A4161A'],
        ] as $slice) {
            $value = (int) $collection[$slice['key']];
            $fraction = $value / $donutTotal;
            $donutSlices[] = [
                'label' => $slice['label'],
                'colour' => $slice['colour'],
                'value' => $value,
                'percent' => $fraction * 100,
                'length' => $fraction * $donutCircumference,
                'offset' => -$offset,
            ];
            $offset += $fraction * $donutCircumference;
        }
    }

    // Trend line: 12 monthly points mapped into a 640x180 plot box.
    $trendPath = '';
    $trendArea = '';
    $trendPoints = [];
    if ($chartTab === 'monthly-trend' && count($chartSeries) > 1) {
        $plotWidth = 600.0;
        $plotHeight = 150.0;
        $step = $plotWidth / (count($chartSeries) - 1);
        $scale = $seriesMax > 0 ? $seriesMax : 1;
        $i = 0;
        foreach ($chartSeries as $point) {
            $x = 30 + ($i * $step);
            $y = 20 + ($plotHeight - (((int) $point['amount'] / $scale) * $plotHeight));
            $trendPoints[] = ['x' => $x, 'y' => $y, 'label' => $point['label'], 'amount' => (int) $point['amount']];
            $trendPath .= ($i === 0 ? 'M' : ' L').' '.round($x, 2).' '.round($y, 2);
            $i++;
        }
        $trendArea = 'M 30 170 L'.substr($trendPath, 1).' L '.round(30 + (($i - 1) * $step), 2).' 170 Z';
    }
@endphp

<div class="min-w-0 space-y-4 print:space-y-2">
    <style>
        @media print {
            nav, aside, header.app-header, .no-print { display: none !important; }
            body { background: #fff; }
            .print-break-inside-avoid { break-inside: avoid; }
        }
    </style>

    {{-- ---------------------------------------------------------------
         Header: title, resolved window, axis + period selectors, actions
         --------------------------------------------------------------- --}}
    <div class="rounded border border-border-primary bg-white">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border-primary px-4 py-3">
            <div class="min-w-0">
                <nav aria-label="Breadcrumb" class="text-xs text-charcoal/50">
                    Dashboard <span aria-hidden="true">/</span> Ledger <span aria-hidden="true">/</span> Finance Dashboard
                </nav>
                <h1 class="mt-0.5 text-lg font-semibold text-charcoal">Finance Dashboard</h1>
                <p class="mt-0.5 text-xs text-charcoal/70">
                    {{-- §21.3 rule 1: the axis and the resolved window are stated, never implied. --}}
                    Axis: <span class="font-medium text-charcoal">{{ $axisLabel }}</span>
                    <span aria-hidden="true">·</span>
                    {{ $window['label'] }}
                    ({{ \Illuminate\Support\Carbon::parse($window['start'])->format('d/m/Y') }}
                    – {{ \Illuminate\Support\Carbon::parse($window['end'])->format('d/m/Y') }})
                    <span aria-hidden="true">·</span>
                    compared with {{ $window['prev_label'] }}
                </p>
                <p class="mt-0.5 text-xs text-charcoal/50">
                    Every ledger figure below is taken through the posted ledger (posted and reversed entries); drafts are excluded.
                </p>
            </div>

            <div class="flex items-center gap-2 no-print">
                <button type="button" wire:click="exportPdf"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export PDF
                </button>
                <button type="button" onclick="window.print()"
                        class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    Print
                </button>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3 px-4 py-3 no-print">
            <label for="fd-axis" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Axis</span>
                <select id="fd-axis" wire:model.live="axis"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="fiscal_year">Fiscal year</option>
                    <option value="academic_year">Academic year</option>
                </select>
            </label>

            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Period</span>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Reporting period">
                    @foreach ($periodTabs as $option)
                        <button type="button" wire:click="selectPeriod('{{ $option['value'] }}')"
                                @if ($period === $option['value']) aria-pressed="true" @else aria-pressed="false" @endif
                                class="rounded border px-3 py-1.5 text-sm {{ $period === $option['value']
                                    ? 'border-primary bg-primary text-white'
                                    : 'border-border-primary text-charcoal hover:border-primary/50' }}">
                            {{ $option['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($period === 'term')
                <label for="fd-term" class="flex min-w-[12rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Term</span>
                    <select id="fd-term" wire:model.live="termId"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">Current</option>
                        @foreach ($termOptions as $term)
                            <option value="{{ $term['id'] }}">{{ $term['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($period === 'custom')
                <label for="fd-from" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">From</span>
                    <input id="fd-from" type="date" wire:model.live="from"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                </label>
                <label for="fd-to" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">To</span>
                    <input id="fd-to" type="date" wire:model.live="to"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                </label>
            @endif
        </div>
    </div>

    {{-- --------------------------------- KPI row --------------------------------- --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($kpis as $kpi)
            <x-kpi-card
                wire:key="kpi-{{ $kpi['key'] }}"
                :label="$kpi['label']"
                :value="$kpi['value'] === '' ? null : $kpi['value']"
                :trend="$kpi['trend']"
                :delta="$kpi['delta'] === null ? null : number_format(abs($kpi['delta']), 1).'% vs '.$window['prev_label']"
                class="print-break-inside-avoid"
            >
                @if ($kpi['delta'] === null)
                    <p class="mt-0.5 text-xs text-charcoal/50">No comparable figure for {{ $window['prev_label'] }}</p>
                @endif
            </x-kpi-card>
        @endforeach
    </div>

    {{-- ------------------------- Treasury Position (§11.3) ------------------------- --}}
    <section aria-labelledby="fd-treasury" class="rounded border border-border-primary bg-white print-break-inside-avoid">
        <div class="border-b border-border-primary px-4 py-3">
            <h2 id="fd-treasury" class="text-sm font-semibold text-charcoal">Treasury Position</h2>
            <p class="mt-0.5 text-xs text-charcoal/60">
                Where the money actually sits, as at {{ \Illuminate\Support\Carbon::parse($window['end'])->format('d/m/Y') }}.
                Each float is shown on its own line and never summed into a single &ldquo;cash&rdquo; figure.
            </p>
        </div>

        @if (count($treasury) === 0)
            <x-empty-state class="m-4" message="No postable class-5 treasury account exists in the chart of accounts yet, so there is no float to report." />
        @else
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($treasury as $account)
                    <div wire:key="treasury-{{ $account['code'] }}" class="rounded border border-border-primary px-3 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-charcoal/60">{{ $account['group'] }}</p>
                        <p class="mt-1 font-mono text-lg font-semibold {{ $account['balance'] < 0 ? 'text-heritage-red' : 'text-charcoal' }}">
                            {{ Money::of($account['balance'])->format(false) }}
                        </p>
                        <p class="mt-0.5 truncate text-xs text-charcoal/50" title="{{ $account['code'] }} — {{ $account['name'] }}">
                            {{ $account['code'] }} — {{ $account['name'] }}
                        </p>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-between border-t border-border-primary px-4 py-2.5">
                <span class="text-xs font-medium uppercase tracking-wide text-charcoal/60">Total treasury</span>
                <span class="font-mono text-sm font-semibold text-charcoal">{{ Money::of($treasuryTotal)->format() }}</span>
            </div>
        @endif
    </section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        {{-- ------------------- Income & Expenses overview (tabbed) ------------------- --}}
        <section aria-labelledby="fd-overview" class="rounded border border-border-primary bg-white xl:col-span-2 print-break-inside-avoid">
            <div class="flex flex-wrap items-start justify-between gap-2 border-b border-border-primary px-4 py-3">
                <div>
                    <h2 id="fd-overview" class="text-sm font-semibold text-charcoal">Income &amp; Expenses Overview</h2>
                    <p class="mt-0.5 text-xs text-charcoal/60">
                        Income {{ Money::of((int) collect($incomeByCategory)->sum('amount'))->format(false) }}
                        <span aria-hidden="true">·</span>
                        Expenses {{ Money::of($expenseTotal)->format(false) }}
                        over {{ $window['label'] }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-1 border-b border-border-primary px-4 no-print" role="tablist">
                @foreach ($chartTabs as $option)
                    <button type="button" role="tab" wire:click="selectChartTab('{{ $option['value'] }}')"
                            @if ($chartTab === $option['value']) aria-selected="true" @else aria-selected="false" @endif
                            class="whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $chartTab === $option['value']
                                ? 'border-primary font-semibold text-primary'
                                : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                        {{ $option['label'] }}
                    </button>
                @endforeach
            </div>

            <div class="p-4">
                @if (count($chartSeries) === 0)
                    @if ($chartTab === 'expense-category')
                        <x-empty-state message="No expense has been booked to a class-6 account in this period. Expense capture is not yet in service, so this panel stays empty rather than showing a zero that could be mistaken for a real figure." />
                    @else
                        <x-empty-state message="No income was billed in this period." />
                    @endif
                @elseif ($chartTab === 'monthly-trend')
                    {{-- Line/area chart: twelve monthly collection totals. --}}
                    <figure class="overflow-x-auto">
                        <svg viewBox="0 0 660 220" role="img" class="h-auto w-full min-w-[560px]"
                             aria-labelledby="fd-trend-title fd-trend-desc">
                            <title id="fd-trend-title">Monthly collection trend</title>
                            <desc id="fd-trend-desc">Cleared receipts per month over the twelve months ending {{ \Illuminate\Support\Carbon::parse($window['end'])->format('F Y') }}.</desc>

                            <line x1="30" y1="170" x2="630" y2="170" stroke="#D8D2C4" stroke-width="1"/>
                            <line x1="30" y1="20" x2="30" y2="170" stroke="#D8D2C4" stroke-width="1"/>

                            <path d="{{ $trendArea }}" fill="#0F5132" fill-opacity="0.10"/>
                            <path d="{{ $trendPath }}" fill="none" stroke="#0F5132" stroke-width="2.5"
                                  stroke-linejoin="round" stroke-linecap="round"/>

                            @foreach ($trendPoints as $index => $point)
                                <circle cx="{{ round($point['x'], 2) }}" cy="{{ round($point['y'], 2) }}" r="3" fill="#0F5132">
                                    <title>{{ $point['label'] }}: {{ Money::of($point['amount'])->format() }}</title>
                                </circle>
                                <text x="{{ round($point['x'], 2) }}" y="188" text-anchor="middle"
                                      font-size="9" fill="#4A4A48">{{ $point['label'] }}</text>
                            @endforeach

                            <text x="30" y="14" font-size="9" fill="#4A4A48">{{ Money::of($seriesMax)->format(false) }}</text>
                        </svg>
                        <figcaption class="sr-only">
                            @foreach ($chartSeries as $point)
                                {{ $point['label'] }}: {{ Money::of($point['amount'])->format() }}.
                            @endforeach
                        </figcaption>
                    </figure>
                @else
                    {{-- Horizontal bars, one per category/account. --}}
                    <ul class="space-y-2.5">
                        @foreach ($chartSeries as $point)
                            @php $width = $seriesMax > 0 ? ((int) $point['amount'] / $seriesMax) * 100 : 0; @endphp
                            <li wire:key="series-{{ $chartTab }}-{{ $loop->index }}">
                                <div class="flex items-baseline justify-between gap-3 text-sm">
                                    <span class="truncate text-charcoal">{{ $point['label'] }}</span>
                                    <span class="shrink-0 font-mono text-charcoal">{{ Money::of($point['amount'])->format(false) }}</span>
                                </div>
                                <div class="mt-1 h-2.5 w-full overflow-hidden rounded bg-sand/60"
                                     role="img" aria-label="{{ $point['label'] }}: {{ Money::of($point['amount'])->format() }}">
                                    <div class="h-full rounded {{ $chartTab === 'expense-category' ? 'bg-heritage-red' : 'bg-primary' }}"
                                         style="width: {{ round($width, 2) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        {{-- ----------------------- Fee Collection Summary donut ----------------------- --}}
        <section aria-labelledby="fd-donut" class="rounded border border-border-primary bg-white print-break-inside-avoid">
            <div class="border-b border-border-primary px-4 py-3">
                <h2 id="fd-donut" class="text-sm font-semibold text-charcoal">Fee Collection Summary</h2>
                <p class="mt-0.5 text-xs text-charcoal/60">
                    All issued invoices raised on or before {{ \Illuminate\Support\Carbon::parse($window['end'])->format('d/m/Y') }}.
                </p>
            </div>

            @if ($donutTotal <= 0)
                <x-empty-state class="m-4" message="No invoice has been issued on or before this date, so there is nothing to collect." />
            @else
                <div class="p-4">
                    <figure class="flex justify-center">
                        <svg viewBox="0 0 200 200" role="img" class="h-44 w-44"
                             aria-labelledby="fd-donut-title fd-donut-desc">
                            <title id="fd-donut-title">Fee collection summary</title>
                            <desc id="fd-donut-desc">
                                @foreach ($donutSlices as $slice){{ $slice['label'] }} {{ number_format($slice['percent'], 1) }}%. @endforeach
                            </desc>
                            <circle cx="100" cy="100" r="70" fill="none" stroke="#EFEBE1" stroke-width="26"/>
                            @foreach ($donutSlices as $slice)
                                @if ($slice['length'] > 0)
                                    <circle cx="100" cy="100" r="70" fill="none"
                                            stroke="{{ $slice['colour'] }}" stroke-width="26"
                                            stroke-dasharray="{{ round($slice['length'], 2) }} {{ round($donutCircumference - $slice['length'], 2) }}"
                                            stroke-dashoffset="{{ round($slice['offset'], 2) }}"
                                            transform="rotate(-90 100 100)">
                                        <title>{{ $slice['label'] }}: {{ Money::of($slice['value'])->format() }}</title>
                                    </circle>
                                @endif
                            @endforeach
                            <text x="100" y="96" text-anchor="middle" font-size="13" font-weight="600" fill="#2B2B29">
                                {{ number_format(($collection['collected'] / max(1, $donutTotal)) * 100, 1) }}%
                            </text>
                            <text x="100" y="112" text-anchor="middle" font-size="9" fill="#4A4A48">collected</text>
                        </svg>
                    </figure>

                    <ul class="mt-4 space-y-2">
                        @foreach ($donutSlices as $slice)
                            <li class="flex items-center justify-between gap-2 text-sm">
                                <span class="flex items-center gap-2 text-charcoal">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-sm" style="background: {{ $slice['colour'] }}" aria-hidden="true"></span>
                                    {{ $slice['label'] }} ({{ number_format($slice['percent'], 1) }}%)
                                </span>
                                <span class="font-mono text-charcoal">{{ Money::of($slice['value'])->format(false) }}</span>
                            </li>
                        @endforeach
                        <li class="flex items-center justify-between gap-2 border-t border-border-primary pt-2 text-sm font-semibold">
                            <span class="text-charcoal">Total invoiced</span>
                            <span class="font-mono text-charcoal">{{ Money::of($collection['total'])->format(false) }}</span>
                        </li>
                    </ul>
                </div>
            @endif
        </section>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        {{-- --------------------------- Income by Category --------------------------- --}}
        <section aria-labelledby="fd-income" class="rounded border border-border-primary bg-white print-break-inside-avoid">
            <div class="border-b border-border-primary px-4 py-3">
                <h2 id="fd-income" class="text-sm font-semibold text-charcoal">Income by Category</h2>
                <p class="mt-0.5 text-xs text-charcoal/60">Billed in {{ $window['label'] }}.</p>
            </div>

            @if (count($incomeByCategory) === 0)
                <x-empty-state class="m-4" message="No invoice line was billed in this period." />
            @else
                <div class="overflow-x-auto p-4">
                    <svg viewBox="0 0 {{ max(240, count($incomeByCategory) * 70) }} 200" role="img"
                         class="h-48 w-full" preserveAspectRatio="xMidYMax meet"
                         aria-labelledby="fd-income-title fd-income-desc">
                        <title id="fd-income-title">Income by fee category</title>
                        <desc id="fd-income-desc">
                            @foreach ($incomeByCategory as $point){{ $point['label'] }}: {{ Money::of($point['amount'])->format() }}. @endforeach
                        </desc>
                        <line x1="0" y1="160" x2="{{ max(240, count($incomeByCategory) * 70) }}" y2="160" stroke="#D8D2C4" stroke-width="1"/>
                        @foreach ($incomeByCategory as $index => $point)
                            @php
                                $height = $incomeMax > 0 ? ((int) $point['amount'] / $incomeMax) * 130 : 0;
                                $x = ($index * 70) + 18;
                            @endphp
                            <rect x="{{ $x }}" y="{{ round(160 - $height, 2) }}" width="34"
                                  height="{{ round(max($height, 1), 2) }}" rx="3" fill="#0F5132">
                                <title>{{ $point['label'] }}: {{ Money::of($point['amount'])->format() }}</title>
                            </rect>
                            <text x="{{ $x + 17 }}" y="{{ round(154 - $height, 2) }}" text-anchor="middle"
                                  font-size="9" fill="#4A4A48">{{ Money::of($point['amount'])->format(false) }}</text>
                            <text x="{{ $x + 17 }}" y="174" text-anchor="middle" font-size="9" fill="#4A4A48">
                                {{ \Illuminate\Support\Str::limit($point['label'], 12, '…') }}
                            </text>
                        @endforeach
                    </svg>
                </div>
            @endif
        </section>

        {{-- ------------------------- Top Outstanding Invoices ------------------------- --}}
        <section aria-labelledby="fd-top-outstanding" class="rounded border border-border-primary bg-white xl:col-span-2 print-break-inside-avoid">
            <div class="border-b border-border-primary px-4 py-3">
                <h2 id="fd-top-outstanding" class="text-sm font-semibold text-charcoal">Top Outstanding Invoices</h2>
                <p class="mt-0.5 text-xs text-charcoal/60">
                    Balance as at {{ \Illuminate\Support\Carbon::parse($window['end'])->format('d/m/Y') }}: gross, less allocations, approved adjustments and issued credit notes.
                </p>
            </div>

            @if (count($topOutstanding) === 0)
                <x-empty-state class="m-4" message="Every issued invoice is settled as at this date." />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Invoice</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Student</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Due</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Invoiced</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-primary">
                            @foreach ($topOutstanding as $invoice)
                                <tr wire:key="outstanding-{{ $invoice['id'] }}">
                                    <td class="px-4 py-2.5 font-mono text-charcoal">{{ $invoice['invoice_no'] }}</td>
                                    <td class="px-4 py-2.5 text-charcoal">{{ $invoice['student'] }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">
                                        {{ \Illuminate\Support\Carbon::parse($invoice['due_date'])->format('d/m/Y') }}
                                        @if ($invoice['days_overdue'] > 0)
                                            <span class="ml-1 text-xs font-medium text-heritage-red">{{ $invoice['days_overdue'] }}d overdue</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono text-charcoal/80">{{ Money::of($invoice['gross'])->format(false) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono font-semibold text-charcoal">{{ Money::of($invoice['outstanding'])->format(false) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    {{-- --------------------------- Recent Transactions --------------------------- --}}
    <section aria-labelledby="fd-transactions" class="rounded border border-border-primary bg-white print-break-inside-avoid">
        <div class="border-b border-border-primary px-4 py-3">
            <h2 id="fd-transactions" class="text-sm font-semibold text-charcoal">Recent Transactions</h2>
            <p class="mt-0.5 text-xs text-charcoal/60">The twelve most recent receipts in {{ $window['label'] }}.</p>
        </div>

        @if (count($transactions) === 0)
            <x-empty-state class="m-4" message="No receipt was recorded in this period." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-chrome text-white">
                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Date</th>
                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Receipt No.</th>
                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Description</th>
                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Student / Client</th>
                            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Amount</th>
                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Type</th>
                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Payment Method</th>
                            <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-primary">
                        @foreach ($transactions as $transaction)
                            <tr wire:key="txn-{{ $transaction['id'] }}">
                                <td class="px-4 py-2.5 text-charcoal/80">{{ \Illuminate\Support\Carbon::parse($transaction['date'])->format('d/m/Y') }}</td>
                                <td class="px-4 py-2.5 font-mono text-charcoal">{{ $transaction['receipt_no'] }}</td>
                                <td class="px-4 py-2.5 text-charcoal">{{ $transaction['description'] }}</td>
                                <td class="px-4 py-2.5 text-charcoal">{{ $transaction['client'] }}</td>
                                <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of($transaction['amount'])->format(false) }}</td>
                                <td class="px-4 py-2.5 text-charcoal/80">{{ $transaction['type'] }}</td>
                                <td class="px-4 py-2.5 text-charcoal/80">{{ $transaction['method'] }}</td>
                                <td class="px-4 py-2.5">
                                    <x-status-pill :status="$transaction['tone']" :label="$transaction['status']"/>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        {{-- ------------------------------ Quick Actions ------------------------------ --}}
        <section aria-labelledby="fd-actions" class="rounded border border-border-primary bg-white no-print">
            <div class="border-b border-border-primary px-4 py-3">
                <h2 id="fd-actions" class="text-sm font-semibold text-charcoal">Quick Actions</h2>
            </div>

            @if (count($quickActions) === 0)
                <x-empty-state class="m-4" message="You do not hold a right that any finance action on this screen requires." />
            @else
                <div class="flex flex-wrap gap-2 p-4">
                    @foreach ($quickActions as $action)
                        <a href="{{ $action['url'] }}"
                           class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ $action['label'] }}
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ------------------------------ Notifications ------------------------------ --}}
        <section aria-labelledby="fd-notifications" class="rounded border border-border-primary bg-white print-break-inside-avoid">
            <div class="border-b border-border-primary px-4 py-3">
                <h2 id="fd-notifications" class="text-sm font-semibold text-charcoal">Notifications</h2>
            </div>

            @if (count($notifications) === 0)
                <x-empty-state class="m-4" message="Nothing needs attention: no overdue balance, no unallocated receipt, no draft entry." />
            @else
                <ul class="divide-y divide-border-primary">
                    @foreach ($notifications as $notification)
                        <li class="flex items-start gap-3 px-4 py-3">
                            <x-status-pill :status="$notification['tone']" :label="$notification['tone'] === 'red' ? 'Action' : 'Review'"/>
                            <span class="text-sm text-charcoal">{{ $notification['message'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
