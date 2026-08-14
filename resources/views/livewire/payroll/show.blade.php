@php
    use App\Support\Money\Money;

    $runTone = [
        'draft' => 'amber',
        'calculating' => 'amber',
        'calculated' => 'blue',
        'approved' => 'blue',
        'paid' => 'ok',
        'closed' => 'ok',
        'cancelled' => 'red',
    ];

    $label = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));
    $status = $run->status->value;
    $monthLabel = $run->payroll_month->format('F Y');
@endphp

<div class="min-w-0 space-y-4 print:space-y-2">
    <style media="print">
        aside, nav, header, .payroll-run-screen-only { display: none !important; }
        main { padding: 0 !important; }
        body { background: #fff !important; }
    </style>

    <nav aria-label="Breadcrumb" class="payroll-run-screen-only min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>
                <a href="{{ route('payroll.index') }}" class="hover:text-charcoal">Payroll</a>
            </li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $monthLabel }}</span>
            </li>
        </ol>
    </nav>

    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @error('approve')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">{{ $message }}</p>
    @enderror
    @error('preflight')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">{{ $message }}</p>
    @enderror
    @error('declarations')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">{{ $message }}</p>
    @enderror

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-charcoal">Payroll Run — {{ $monthLabel }}</h1>
            <p class="mt-1 text-sm text-charcoal/70">
                {{ $label($run->run_type->value) }}
                <span aria-hidden="true"> · </span>
                Employer {{ $employerProfile->cnps_employer_number ?? '—' }}
                <span aria-hidden="true"> · </span>
                {{ $totals['staff_count'] }} staff
            </p>
            <div class="mt-2">
                <x-status-pill :status="$runTone[$status] ?? 'ok'" :label="$label($status)"/>
            </div>
        </div>

        <div class="payroll-run-screen-only flex flex-wrap items-center gap-2">
            <a href="{{ route('payroll.index') }}"
               class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Back to Payroll
            </a>
            <button type="button" onclick="window.print()"
                    class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Print run summary
            </button>
            <button type="button" wire:click="downloadRunSummary"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                Export PDF
            </button>
        </div>
    </div>

    {{-- Action bar: mirrors Index.php's per-row actions, scoped to this run. --}}
    <div class="payroll-run-screen-only flex flex-wrap items-center gap-3 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        @if ($canRun && in_array($status, ['draft', 'calculated'], true))
            <button type="button" wire:click="preflightRun"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
                Preflight
            </button>
        @endif
        @if ($canApprove && $status === 'calculated')
            <button type="button" wire:click="approveRun"
                    wire:confirm="Approve this payroll run? This posts the ledger entry and cannot be undone."
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                Approve run
            </button>
        @endif
        @if ($canPay && $status === 'approved')
            <button type="button" wire:click="togglePayForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showPayForm ? 'Hide payment form' : 'Prepare payment' }}
            </button>
        @endif
        @if ($canFileDeclarations && in_array($status, ['paid', 'closed'], true))
            <button type="button" wire:click="generateDeclarations"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
                Generate declarations
            </button>
        @endif
        @if ($canReverse && in_array($status, ['approved', 'paid'], true))
            <button type="button" wire:click="toggleReverseForm"
                    class="rounded border border-heritage-red/50 px-4 py-2 text-sm font-medium text-heritage-red hover:bg-heritage-red/5">
                {{ $showReverseForm ? 'Hide reverse form' : 'Reverse run' }}
            </button>
        @endif
    </div>

    @if ($showPayForm)
        <section aria-label="Prepare payment" class="payroll-run-screen-only rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Prepare Payment</h2>

            <form wire:submit="preparePayment" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-3">
                    <label for="show-pay-method" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Payment method</span>
                        <select id="show-pay-method" wire:model="payMethod"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="bank">Bank</option>
                            <option value="mobile_money">Mobile money</option>
                            <option value="cash">Cash</option>
                        </select>
                    </label>

                    <label for="show-pay-treasury" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Treasury account ID</span>
                        <input id="show-pay-treasury" type="number" min="1" wire:model="payTreasuryAccountId"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('payTreasuryAccountId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="show-pay-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Value date</span>
                        <input id="show-pay-date" type="date" wire:model="payValueDate"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('payValueDate')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Prepare payment
                    </button>
                    <button type="button" wire:click="togglePayForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    @if ($showReverseForm)
        <section aria-label="Reverse payroll run" class="payroll-run-screen-only rounded-lg border border-heritage-red/40 bg-heritage-red/5 p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Reverse This Run</h2>
            <p class="mt-1 text-xs text-charcoal/60">This cancels the run and posts a contrepassation journal entry. It cannot be undone.</p>

            <form wire:submit="reverseRun" class="mt-4 space-y-4">
                <label for="show-reverse-reason" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Reversal reason (minimum 10 characters)</span>
                    <textarea id="show-reverse-reason" wire:model="reverseReason" rows="2"
                              class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    @error('reverseReason')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <div class="flex items-center gap-3">
                    <button type="submit" wire:confirm="Reverse this payroll run? This cancels it and posts a contrepassation entry."
                            class="rounded bg-heritage-red px-4 py-2 text-sm font-semibold text-white hover:bg-heritage-red/90">
                        Reverse run
                    </button>
                    <button type="button" wire:click="toggleReverseForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Totals strip --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-charcoal/60">Total Gross</p>
            <p class="mt-1 text-lg font-semibold text-charcoal tabular-nums">{{ Money::of($totals['gross'])->format(false) }}</p>
        </div>
        <div class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-charcoal/60">Total Deductions</p>
            <p class="mt-1 text-lg font-semibold text-charcoal tabular-nums">{{ Money::of($totals['deductions'])->format(false) }}</p>
        </div>
        <div class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-charcoal/60">Total Net Pay</p>
            <p class="mt-1 text-lg font-semibold text-charcoal tabular-nums">{{ Money::of($totals['net'])->format(false) }}</p>
        </div>
    </div>

    {{-- Per-staff breakdown / payroll register preview --}}
    <div class="min-w-0 overflow-x-auto rounded-lg border border-border-primary bg-white shadow-sm print:overflow-visible print:rounded-none print:border-0">
        <table class="w-full min-w-[36rem] border-collapse text-sm">
            <caption class="px-4 py-3 text-left text-base font-semibold text-charcoal">
                Payroll Register — {{ $monthLabel }}
            </caption>
            <thead class="border-b border-border-primary bg-chrome text-left text-white">
                <tr>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Staff</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Gross</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Deductions</th>
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Net Pay</th>
                    <th scope="col" class="payroll-run-screen-only px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-primary">
                @forelse ($staffRows as $staffRow)
                    <tr wire:key="payroll-item-{{ $staffRow->payroll_item_id }}" class="hover:bg-sand/30">
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $staffRow->staff_name }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $staffRow->gross)->format(false) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $staffRow->total_employee_deductions)->format(false) }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ Money::of((int) $staffRow->net)->format(false) }}</td>
                        <td class="payroll-run-screen-only px-4 py-2.5 text-right">
                            {{-- 10-documents §11.1: the official payslip opens
                                 INLINE in a new tab (the shape
                                 Fees\Http\Controllers\PrintInvoiceController
                                 established). url() rather than route()
                                 because the route is wired centrally in
                                 routes/web.php. Only offered on an approved
                                 run - a draft calculation is a working figure,
                                 not a pay document. --}}
                            @if ($isIssuable && ! $staffRow->is_cancelled)
                                {{-- Also gated on the ITEM, not just the run:
                                     a cancelled line has no payslip to issue
                                     and PrintPayslip correctly answers 422.
                                     Offering a control that can only fail is
                                     worse than offering none - the operator
                                     reads the refusal as a bug. --}}
                                <a href="{{ url('/payroll/payslips/'.$staffRow->payroll_item_id.'/print') }}"
                                   target="_blank" rel="noopener"
                                   class="text-sm font-medium text-primary hover:underline">
                                    Print payslip
                                </a>
                                <span class="px-1 text-charcoal/30">·</span>
                            @endif
                            <button type="button" wire:click="downloadPayslip({{ $staffRow->payroll_item_id }})"
                                    class="text-sm font-medium text-primary hover:underline">
                                Download
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-charcoal/60">No staff items on this run yet.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="border-t border-border-primary bg-sand/30">
                <tr>
                    <th scope="row" colspan="3" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide">Total</th>
                    <td class="px-4 py-2 text-right font-mono font-bold text-charcoal">{{ Money::of($totals['net'])->format() }}</td>
                    <td class="payroll-run-screen-only"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="payroll-run-screen-only text-xs text-charcoal/50 print:hidden">
        Signature: __________________________ &nbsp;&nbsp;&nbsp; Date: __________________
    </p>
</div>
