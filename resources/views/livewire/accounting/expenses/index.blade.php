{{-- docs/specs/02-accounting.md §21.3 "Expense capture". No dedicated
     mockup exists, so the chrome mirrors the house list screens exactly:
     KPI strip, filter bar, tabs by status, right rail. The record panel is
     the inline toggle-form pattern (Welfare\Visitors) because a petty-cash
     voucher is keyed at a desk with the register still on screen.

     Single root element - a second one breaks Livewire morphing. --}}

@php
    use App\Support\Money\Money;

    $tabs = [
        ['value' => 'all', 'label' => 'All'],
        ['value' => 'draft', 'label' => 'Draft'],
        ['value' => 'submitted', 'label' => 'Awaiting Approval'],
        ['value' => 'approved', 'label' => 'Ready to Post'],
        ['value' => 'posted', 'label' => 'Posted'],
        ['value' => 'rejected', 'label' => 'Rejected'],
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @error('rowAction')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
            {{ $message }}
        </p>
    @enderror

    {{-- Reject dialog: a refusal without a reason cannot be answered. --}}
    @if ($rejectingId !== null)
        <section aria-label="Reject expense" class="rounded-lg border border-heritage-red/40 bg-heritage-red/5 p-4">
            <h2 class="text-sm font-semibold text-charcoal">Reject this expense</h2>
            <div class="mt-2 flex flex-wrap items-end gap-3">
                <label for="expense-reject-reason" class="flex min-w-[18rem] flex-1 flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Reason (recorded on the voucher)</span>
                    <input id="expense-reject-reason" type="text" wire:model="rejectReason"
                           placeholder="e.g. No receipt attached; wrong charge account"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('rejectReason')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>
                <button type="button" wire:click="confirmReject"
                        class="rounded bg-heritage-red px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                    Reject
                </button>
                <button type="button" wire:click="cancelReject"
                        class="rounded border border-sand px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </section>
    @endif

    {{-- Record panel with the dynamic line grid. --}}
    @if ($showForm && $canRecord)
        <section aria-label="Record expense" class="rounded-lg border border-sand bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Record Expense</h2>
            <p class="mt-1 text-xs text-charcoal/60">
                For the petty, cash-and-receipt purchase. Where the payee is a registered supplier with an invoice,
                use Procurement instead (02-accounting §21.3).
            </p>

            <form wire:submit="saveExpense" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label for="expense-form-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Date</span>
                        <input id="expense-form-date" type="date" wire:model="formDate"
                               class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="expense-form-payee-type" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Payee type</span>
                        <select id="expense-form-payee-type" wire:model.live="formPayeeType"
                                class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($payeeTypes as $payeeType)
                                <option value="{{ $payeeType->value }}">{{ $payeeType->label() }}</option>
                            @endforeach
                        </select>
                    </label>

                    @if ($formPayeeType === 'other')
                        <label for="expense-form-payee-name" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Payee name</span>
                            <input id="expense-form-payee-name" type="text" wire:model="formPayeeName"
                                   placeholder="e.g. Librairie Centrale, Mme Ngo"
                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('formPayeeName')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                    @else
                        <label for="expense-form-payee-id" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">
                                {{ $formPayeeType === 'supplier' ? 'Supplier record id' : 'Staff user id' }}
                            </span>
                            <input id="expense-form-payee-id" type="number" wire:model="formPayeeId"
                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('formPayeeId')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                    @endif

                    <label for="expense-form-description" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Description</span>
                        <input id="expense-form-description" type="text" wire:model="formDescription"
                               placeholder="e.g. Library books, cash purchase"
                               class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('formDescription')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="expense-form-treasury" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Paid out of (class 5)</span>
                        <select id="expense-form-treasury" wire:model="formTreasuryAccountId"
                                class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Choose a float…</option>
                            @foreach ($treasuryAccounts as $account)
                                <option value="{{ $account['id'] }}">{{ $account['code'] }} — {{ $account['name'] }}</option>
                            @endforeach
                        </select>
                        @error('formTreasuryAccountId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="expense-form-attachment" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Receipt reference (required to submit)</span>
                        <input id="expense-form-attachment" type="text" wire:model="formAttachmentRef"
                               placeholder="e.g. RCPT-2026-0417 / photo file name"
                               class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="expense-form-notes" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Notes (optional)</span>
                        <input id="expense-form-notes" type="text" wire:model="formNotes"
                               class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>
                </div>

                {{-- Charge lines --}}
                <div class="rounded border border-sand">
                    <div class="flex items-center justify-between border-b border-sand bg-sand/30 px-3 py-2">
                        <h3 class="text-sm font-semibold text-charcoal">Charge lines</h3>
                        <button type="button" wire:click="addLine"
                                class="text-sm font-medium text-primary hover:underline">+ Add line</button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-charcoal/60">
                                    <th scope="col" class="px-3 py-2">Account (class 6 or 2)</th>
                                    <th scope="col" class="px-3 py-2">Label</th>
                                    <th scope="col" class="px-3 py-2">Analytic</th>
                                    <th scope="col" class="px-3 py-2 text-right">Amount (FCFA)</th>
                                    <th scope="col" class="px-3 py-2"><span class="sr-only">Remove</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($formLines as $index => $line)
                                    <tr wire:key="expense-line-{{ $index }}" class="border-t border-sand/60">
                                        <td class="px-3 py-2">
                                            <select wire:model="formLines.{{ $index }}.account_id"
                                                    aria-label="Charge account for line {{ $index + 1 }}"
                                                    class="w-full rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                                                <option value="">Choose…</option>
                                                @foreach ($chargeAccounts as $account)
                                                    <option value="{{ $account['id'] }}">{{ $account['code'] }} — {{ $account['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="text" wire:model="formLines.{{ $index }}.label"
                                                   aria-label="Label for line {{ $index + 1 }}"
                                                   placeholder="Defaults to the description"
                                                   class="w-full rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
                                        </td>
                                        <td class="px-3 py-2">
                                            <select wire:model="formLines.{{ $index }}.analytic_value_id"
                                                    aria-label="Analytic value for line {{ $index + 1 }}"
                                                    class="w-full rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                                                <option value="">—</option>
                                                @foreach ($analyticValues as $value)
                                                    <option value="{{ $value['id'] }}">{{ $value['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <input type="number" min="1" step="1"
                                                   wire:model.live.debounce.400ms="formLines.{{ $index }}.amount"
                                                   aria-label="Amount for line {{ $index + 1 }}"
                                                   class="w-32 rounded border border-sand bg-white px-2 py-1.5 text-right font-mono text-sm text-charcoal"/>
                                            @error('formLines.'.$index.'.amount')
                                                <span class="block text-xs text-heritage-red">{{ $message }}</span>
                                            @enderror
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            @if (count($formLines) > 1)
                                                <button type="button" wire:click="removeLine({{ $index }})"
                                                        class="text-sm text-heritage-red hover:underline">Remove</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-sand bg-sand/20">
                                    <td colspan="3" class="px-3 py-2 text-right text-xs font-semibold uppercase text-charcoal/60">Total</td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold text-charcoal">
                                        {{ Money::of($formTotal)->format(false) }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Save draft
                    </button>
                    <button type="button" wire:click="toggleForm"
                            class="rounded border border-sand px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    <x-list-screen
        title="Expenses"
        :breadcrumb="['Dashboard', 'Accounting', 'Expenses']"
        :paginator="$rows"
        empty-message="No expenses match these filters. Petty-cash vouchers appear here as they are recorded."
        rail-title="Spending Overview"
    >
        <x-slot:actions>
            @if ($canRecord)
                <button type="button" wire:click="toggleForm"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    {{ $showForm ? 'Hide form' : 'Record expense' }}
                </button>
            @endif
        </x-slot:actions>

        <x-slot:kpis>
            <x-kpi-card label="Posted This Month" :value="Money::of($kpis['month_total'])->format(false)" icon-bg="bg-primary">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Awaiting Approval" :value="$kpis['awaiting_approval']" icon-bg="bg-badge-orange">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Ready To Post" :value="$kpis['awaiting_posting']" icon-bg="bg-badge-blue">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Vouchers This Month" :value="$kpis['posted_this_month']" icon-bg="bg-chrome">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6M9 16h6M7 3h10a2 2 0 012 2v16l-3-2-2 2-2-2-2 2-2-2-3 2V5a2 2 0 012-2z"/></svg>
                </x-slot:icon>
            </x-kpi-card>
        </x-slot:kpis>

        <x-slot:filters>
            <label for="expense-filter-search" class="flex min-w-[12rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Search</span>
                <input id="expense-filter-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Voucher no., payee, description..."
                       class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>

            <label for="expense-filter-treasury" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Paid out of</span>
                <select id="expense-filter-treasury" wire:model.live="treasury"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All floats</option>
                    @foreach ($treasuryAccounts as $account)
                        <option value="{{ $account['id'] }}">{{ $account['code'] }} — {{ $account['name'] }}</option>
                    @endforeach
                </select>
            </label>

            <label for="expense-filter-from" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">From</span>
                <input id="expense-filter-from" type="date" wire:model.live="from"
                       class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>

            <label for="expense-filter-to" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">To</span>
                <input id="expense-filter-to" type="date" wire:model.live="to"
                       class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>

            <button type="button" wire:click="resetFilters"
                    class="self-end rounded border border-sand px-3 py-1.5 text-sm text-charcoal/70 hover:text-charcoal">
                Reset
            </button>
        </x-slot:filters>

        <x-slot:tabs>
            @foreach ($tabs as $tabOption)
                <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                        @if ($tab === $tabOption['value']) aria-current="page" @endif
                        class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                            ? 'border-primary font-semibold text-primary'
                            : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                    {{ $tabOption['label'] }}
                    <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tabCounts[$tabOption['value']] ?? 0 }}</span>
                </button>
            @endforeach
        </x-slot:tabs>

        <x-slot:head>
            <tr class="bg-chrome text-white">
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Voucher</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Payee</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Description</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Amount</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr wire:key="expense-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
                <td class="px-4 py-2.5 font-mono">
                    <a href="{{ url('/accounting/expenses/'.$row->id) }}" class="text-primary hover:underline">{{ $row->expense_no }}</a>
                </td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->expense_date->format('Y-m-d') }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">
                    {{ $row->payee_name }}
                    <span class="ml-1 text-xs text-charcoal/50">({{ $row->payee_type->label() }})</span>
                </td>
                <td class="max-w-[18rem] truncate px-4 py-2.5 text-charcoal/80">{{ $row->description }}</td>
                <td class="px-4 py-2.5 text-right font-mono">{{ Money::of($row->total_amount)->format(false) }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->status->tone()" :label="$row->status->label()"/>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <div class="flex items-center justify-end gap-3">
                        @if ($canRecord && $row->status->value === 'draft')
                            <button type="button" wire:click="submit({{ $row->id }})"
                                    class="text-sm font-medium text-primary hover:underline">Submit</button>
                        @endif
                        @if ($canApprove && $row->status->value === 'submitted')
                            <button type="button" wire:click="approve({{ $row->id }})"
                                    class="text-sm font-medium text-primary hover:underline">Approve</button>
                            <button type="button" wire:click="startReject({{ $row->id }})"
                                    class="text-sm font-medium text-heritage-red hover:underline">Reject</button>
                        @endif
                        @if ($canPost && $row->status->value === 'approved')
                            <button type="button" wire:click="post({{ $row->id }})"
                                    class="text-sm font-medium text-primary hover:underline">Post</button>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach

        <x-slot:cards>
            @foreach ($rows as $row)
                <article wire:key="expense-card-{{ $tab }}-{{ $row->id }}" class="rounded border border-sand bg-white p-3">
                    <div class="flex items-center justify-between gap-2">
                        <a href="{{ url('/accounting/expenses/'.$row->id) }}" class="font-mono text-sm text-primary hover:underline">{{ $row->expense_no }}</a>
                        <x-status-pill :status="$row->status->tone()" :label="$row->status->label()"/>
                    </div>
                    <p class="mt-1 font-medium text-charcoal">{{ $row->payee_name }}</p>
                    <p class="text-sm text-charcoal/70">{{ $row->expense_date->format('Y-m-d') }} · {{ Money::of($row->total_amount)->format(false) }}</p>
                    <p class="mt-1 truncate text-sm text-charcoal/60">{{ $row->description }}</p>
                </article>
            @endforeach
        </x-slot:cards>

        <x-slot:rail>
            <div class="space-y-4">
                <section aria-label="Spend by float" class="rounded border border-sand bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Spend by Float (90 days)</h3>
                    @if ($spendByFloat === [])
                        <p class="text-sm text-charcoal/60">Nothing posted in the last 90 days.</p>
                    @else
                        @php $floatMax = max(array_column($spendByFloat, 'total')) ?: 1; @endphp
                        <ul class="space-y-2.5">
                            @foreach ($spendByFloat as $entry)
                                <li>
                                    <div class="flex items-center justify-between gap-2 text-xs text-charcoal/70">
                                        <span class="truncate">{{ $entry['label'] }}</span>
                                        <span class="tabular-nums">{{ Money::of($entry['total'])->format(false) }}</span>
                                    </div>
                                    <div class="mt-1 h-1.5 w-full rounded-full bg-sand">
                                        <div class="h-1.5 rounded-full bg-primary"
                                             style="width: {{ (int) round($entry['total'] * 100 / $floatMax) }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section aria-label="Spend by account" class="rounded border border-sand bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Top Charge Accounts (90 days)</h3>
                    @if ($spendByAccount === [])
                        <p class="text-sm text-charcoal/60">Nothing posted in the last 90 days.</p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($spendByAccount as $entry)
                                <li class="flex items-start justify-between gap-2 text-sm">
                                    <span class="truncate text-charcoal/80">{{ $entry['label'] }}</span>
                                    <span class="shrink-0 font-mono text-charcoal">{{ Money::of($entry['total'])->format(false) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </x-slot:rail>
    </x-list-screen>
</div>
