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

    @if (session('status'))
        <div class="rounded border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex justify-end">
        <button type="button" wire:click="toggleGenerateForm"
                class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white">
            {{ $showGenerateForm ? 'Cancel' : 'Generate a declaration' }}
        </button>
    </div>

    @if ($showGenerateForm)
        <form wire:submit="generate" class="space-y-3 rounded border border-sand bg-white p-4">
            @error('generate') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Declaration type</span>
                <select wire:model.live="genType" class="rounded border border-sand px-2 py-1.5 text-sm">
                    <option value="tva_monthly">TVA (monthly)</option>
                    <option value="withholding_monthly">Withholding (monthly)</option>
                    <option value="dsf_annual">DSF (annual)</option>
                </select>
            </label>

            @if ($genType === 'dsf_annual')
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Fiscal year (closing or closed)</span>
                    <select wire:model="genFiscalYearId" class="rounded border border-sand px-2 py-1.5 text-sm">
                        <option value="">&mdash;</option>
                        @foreach ($availableFiscalYears as $fiscalYear)
                            <option value="{{ $fiscalYear->id }}">{{ $fiscalYear->code }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Period year</span>
                        <input type="number" wire:model="genYear" class="rounded border border-sand px-2 py-1.5 text-sm"/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Period month (1-12)</span>
                        <input type="number" min="1" max="12" wire:model="genMonth" class="rounded border border-sand px-2 py-1.5 text-sm"/>
                    </label>
                </div>
            @endif

            <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">Generate</button>
        </form>
    @endif

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
