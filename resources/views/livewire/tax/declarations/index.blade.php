{{--
    03-tax-procurement §10 - the declarations register. Read-only:
    declarations are generated/filed/amended through the audited Actions.
--}}
<div class="mx-auto max-w-6xl space-y-6 p-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">Tax declarations</h1>
        <p class="text-sm text-charcoal/70">
            Generated figures only - the bursar files on impots.cm and records the acknowledgement here.
        </p>
    </div>

    <div class="flex flex-wrap gap-3">
        <label class="text-sm text-charcoal/80">
            Status
            <select wire:model.live="status" class="ml-1 rounded border border-sand bg-white px-2 py-1 text-sm">
                <option value="">All</option>
                @foreach (['draft', 'generated', 'under_review', 'filed', 'paid', 'amended', 'cancelled'] as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-charcoal/80">
            Type
            <select wire:model.live="type" class="ml-1 rounded border border-sand bg-white px-2 py-1 text-sm">
                <option value="">All</option>
                @foreach (\App\Modules\Tax\Models\TaxDeclarationType::query()->orderBy('code')->pluck('code') as $code)
                    <option value="{{ $code }}">{{ $code }}</option>
                @endforeach
            </select>
        </label>
    </div>

    @if ($declarations->isEmpty())
        <p class="rounded border border-sand bg-white px-3 py-4 text-sm text-charcoal/70">
            No declaration matches. Declarations are generated period by period once the accounting period is locked.
        </p>
    @else
        <div class="overflow-x-auto rounded-lg border border-sand bg-white">
            <table class="min-w-full divide-y divide-sand text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-charcoal/60">
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2">Period</th>
                        <th class="px-3 py-2">Due</th>
                        <th class="px-3 py-2">Declared</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Reference</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    @foreach ($declarations as $declaration)
                        <tr wire:key="declaration-{{ $declaration->id }}">
                            <td class="px-3 py-2 text-charcoal">{{ $declaration->declaration_type }}</td>
                            <td class="px-3 py-2 text-charcoal/80">
                                {{ $declaration->period_month > 0 ? sprintf('%04d-%02d', $declaration->period_year, $declaration->period_month) : $declaration->period_year }}
                            </td>
                            <td class="px-3 py-2 text-charcoal/80">{{ $declaration->due_date?->toDateString() ?? '—' }}</td>
                            <td class="px-3 py-2 text-right text-charcoal/80">{{ number_format($declaration->amount_declared, 0, ',', ' ') }}</td>
                            <td class="px-3 py-2 text-charcoal/80">{{ $declaration->status->value }}</td>
                            <td class="px-3 py-2 text-charcoal/80">{{ $declaration->external_reference ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('tax.declarations.show', ['declaration' => $declaration->id]) }}" class="text-sm font-medium text-charcoal underline">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $declarations->links() }}
    @endif
</div>
