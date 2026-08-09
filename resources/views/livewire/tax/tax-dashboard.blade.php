{{--
    03-tax-procurement §7.4 / §10 - the tax dashboard: compliance
    calendar with T−15/7/1 alerts, recent declarations, open TVA credit.
    WORDING RULE (§7.4): the system NEVER files anything - it generates
    figures; the bursar files on impots.cm. No phrasing here may imply
    automated filing.
--}}
<div class="mx-auto max-w-6xl space-y-6 p-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">Tax &amp; declarations</h1>
        <p class="text-sm text-charcoal/70">
            This system generates figures and exports; it never files anything.
            Declarations are filed by the bursar on impots.cm and recorded here with their acknowledgement.
        </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-sand bg-white p-4">
            <div class="text-xs uppercase tracking-wide text-charcoal/60">Open TVA credit carried forward</div>
            <div class="mt-1 text-2xl font-semibold text-charcoal">{{ number_format($openCredit, 0, ',', ' ') }} FCFA</div>
        </div>
        <div class="rounded-lg border border-sand bg-white p-4">
            <div class="text-xs uppercase tracking-wide text-charcoal/60">Declarations on record</div>
            <div class="mt-1 text-2xl font-semibold text-charcoal">{{ $recentDeclarations->count() }}</div>
        </div>
    </div>

    <section>
        <h2 class="mb-2 text-base font-semibold text-charcoal">Compliance calendar</h2>
        <p class="mb-2 text-xs text-charcoal/60">
            Statutory dates are shown without weekend or holiday adjustment (roll-forward rule not yet verified).
        </p>

        @foreach ($calendarNotes as $note)
            <p class="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">{{ $note }}</p>
        @endforeach

        @if ($calendarItems === [])
            <p class="rounded border border-sand bg-white px-3 py-4 text-sm text-charcoal/70">
                Nothing to show - configure the tax obligations with your accountant.
            </p>
        @else
            <div class="overflow-x-auto rounded-lg border border-sand bg-white">
                <table class="min-w-full divide-y divide-sand text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-charcoal/60">
                            <th class="px-3 py-2">Obligation</th>
                            <th class="px-3 py-2">Period</th>
                            <th class="px-3 py-2">Due date</th>
                            <th class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand">
                        @foreach ($calendarItems as $item)
                            <tr>
                                <td class="px-3 py-2 text-charcoal">{{ $item['declaration_name'] }}</td>
                                <td class="px-3 py-2 text-charcoal/80">
                                    {{ $item['period_month'] > 0 ? sprintf('%04d-%02d', $item['period_year'], $item['period_month']) : $item['period_year'] }}
                                </td>
                                <td class="px-3 py-2 text-charcoal/80">{{ $item['due_date'] }}</td>
                                <td class="px-3 py-2">
                                    @if ($item['alert_level'] === 'overdue')
                                        <span class="rounded-full border border-red-300 bg-red-50 px-2 py-0.5 text-xs font-medium text-red-800">Overdue - not filed</span>
                                    @elseif ($item['alert_level'] === 'filed')
                                        <span class="rounded-full border border-green-300 bg-green-50 px-2 py-0.5 text-xs font-medium text-green-800">Filed</span>
                                    @elseif ($item['alert_level'] === 'due_today')
                                        <span class="rounded-full border border-red-300 bg-red-50 px-2 py-0.5 text-xs font-medium text-red-800">Due today</span>
                                    @elseif (in_array($item['alert_level'], ['t-1', 't-7', 't-15'], true))
                                        <span class="rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-900">Due soon ({{ strtoupper($item['alert_level']) }})</span>
                                    @else
                                        <span class="rounded-full border border-sand bg-white px-2 py-0.5 text-xs text-charcoal/70">Upcoming</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section>
        <h2 class="mb-2 text-base font-semibold text-charcoal">Recent declarations</h2>

        @if ($recentDeclarations->isEmpty())
            <p class="rounded border border-sand bg-white px-3 py-4 text-sm text-charcoal/70">No declaration has been generated yet.</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-sand bg-white">
                <table class="min-w-full divide-y divide-sand text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-charcoal/60">
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Period</th>
                            <th class="px-3 py-2">Amount</th>
                            <th class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand">
                        @foreach ($recentDeclarations as $declaration)
                            <tr>
                                <td class="px-3 py-2 text-charcoal">{{ $declaration->declaration_type }}</td>
                                <td class="px-3 py-2 text-charcoal/80">
                                    {{ $declaration->period_month > 0 ? sprintf('%04d-%02d', $declaration->period_year, $declaration->period_month) : $declaration->period_year }}
                                </td>
                                <td class="px-3 py-2 text-charcoal/80">{{ number_format($declaration->amount_declared, 0, ',', ' ') }}</td>
                                <td class="px-3 py-2 text-charcoal/80">{{ $declaration->status->value }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
