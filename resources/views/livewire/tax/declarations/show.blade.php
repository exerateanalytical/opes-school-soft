{{--
    03-tax-procurement §10 - one declaration: header, lines, per-supplier
    annex, hash and amendment chain. Carries the §7.1 unmapped-form banner.
--}}
<div class="mx-auto max-w-5xl space-y-6 p-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">
            {{ $declaration->declaration_type }}
            &mdash;
            {{ $declaration->period_month > 0 ? sprintf('%04d-%02d', $declaration->period_year, $declaration->period_month) : $declaration->period_year }}
        </h1>
        <p class="text-sm text-charcoal/70">Status: {{ $declaration->status->value }}</p>
    </div>

    @if ($unmappedForm)
        <p class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Not yet mapped to the official form: the DGI form box codes are unverified, so the lines below
            carry internal codes and this declaration cannot be marked filed until the mapping is configured
            with your accountant.
        </p>
    @endif

    <dl class="grid gap-3 rounded-lg border border-sand bg-white p-4 text-sm sm:grid-cols-3">
        <div>
            <dt class="text-xs uppercase tracking-wide text-charcoal/60">Amount declared</dt>
            <dd class="text-charcoal">{{ number_format($declaration->amount_declared, 0, ',', ' ') }} FCFA</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-charcoal/60">Due date</dt>
            <dd class="text-charcoal">{{ $declaration->due_date?->toDateString() ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-charcoal/60">Filed</dt>
            <dd class="text-charcoal">
                {{ $declaration->filed_at?->toDateString() ?? 'Not filed' }}
                @if ($declaration->external_reference)
                    (ref {{ $declaration->external_reference }})
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-charcoal/60">Inputs hash</dt>
            <dd class="break-all font-mono text-xs text-charcoal/80">{{ $declaration->inputs_hash ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-charcoal/60">Amends</dt>
            <dd class="text-charcoal">{{ $declaration->amends_declaration_id !== null ? 'Declaration #'.$declaration->amends_declaration_id : '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-charcoal/60">Penalty / interest</dt>
            <dd class="text-charcoal">{{ number_format($declaration->penalty_amount, 0, ',', ' ') }} / {{ number_format($declaration->interest_amount, 0, ',', ' ') }}</dd>
        </div>
    </dl>

    @if ($declaration->notes)
        <p class="rounded border border-sand bg-white px-3 py-2 text-sm text-charcoal/80">{{ $declaration->notes }}</p>
    @endif

    <section>
        <h2 class="mb-2 text-base font-semibold text-charcoal">Lines</h2>
        <div class="overflow-x-auto rounded-lg border border-sand bg-white">
            <table class="min-w-full divide-y divide-sand text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-charcoal/60">
                        <th class="px-3 py-2">#</th>
                        <th class="px-3 py-2">Code</th>
                        <th class="px-3 py-2">Label</th>
                        <th class="px-3 py-2">NIU</th>
                        <th class="px-3 py-2 text-right">Base</th>
                        <th class="px-3 py-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    @foreach ($declaration->lines as $line)
                        <tr wire:key="line-{{ $line->id }}">
                            <td class="px-3 py-2 text-charcoal/60">{{ $line->line_no }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-charcoal">{{ $line->line_code }}</td>
                            <td class="px-3 py-2 text-charcoal">{{ $line->label }}</td>
                            <td class="px-3 py-2 text-charcoal/80">{{ $line->supplier_niu ?? '—' }}</td>
                            <td class="px-3 py-2 text-right text-charcoal/80">{{ number_format($line->base_amount, 0, ',', ' ') }}</td>
                            <td class="px-3 py-2 text-right text-charcoal">{{ number_format($line->tax_amount, 0, ',', ' ') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
