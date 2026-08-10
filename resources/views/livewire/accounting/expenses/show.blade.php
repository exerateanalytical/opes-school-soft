{{-- docs/specs/02-accounting.md §21.3 - one expense voucher, with the
     print-preview document the payee signs and the auditor asks for.
     Read-only: submit/approve/post live on the register. Single root
     element - a second one breaks Livewire morphing. --}}

@php
    use App\Support\Money\Money;

    $tone = [
        'draft' => 'amber', 'submitted' => 'amber',
        'approved' => 'ok', 'posted' => 'ok', 'rejected' => 'red',
    ];
@endphp

<div class="mx-auto max-w-5xl space-y-4 p-4">
    <nav class="text-xs text-charcoal/60 print:hidden">
        <a href="{{ url('/accounting/expenses') }}" class="text-primary hover:underline">&larr; Back to expenses</a>
    </nav>

    <header class="flex flex-wrap items-center justify-between gap-2 print:hidden">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ $expense->expense_no }}</h1>
            <p class="text-sm text-charcoal/70">{{ $expense->payee_name }} &middot; {{ $expense->description }}</p>
        </div>
        <div class="flex items-center gap-2">
            <x-status-pill :status="$tone[$expense->status] ?? 'amber'" :label="ucfirst($expense->status)"/>
            <button type="button" onclick="window.print()" class="rounded border border-sand px-3 py-1.5 text-sm hover:bg-sand/40">Print / Preview</button>
            <button type="button" wire:click="exportPdf" class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">Export PDF</button>
        </div>
    </header>

    @if ($expense->status === 'rejected' && $expense->rejection_reason)
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red print:hidden" role="alert">
            Rejected: {{ $expense->rejection_reason }}
        </p>
    @endif

    <dl class="grid grid-cols-1 gap-x-8 gap-y-2 rounded border border-sand bg-white p-4 text-sm sm:grid-cols-2 print:hidden">
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Expense date</dt><dd class="font-medium">{{ $expense->expense_date }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Paid out of</dt><dd class="font-medium font-mono">{{ $expense->treasury_code }} — {{ $expense->treasury_name }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Payee type</dt><dd class="font-medium">{{ ucfirst($expense->payee_type) }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Receipt reference</dt><dd class="font-medium">{{ $expense->attachment_ref ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Recorded by</dt><dd class="font-medium">{{ $createdByName ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Submitted by</dt><dd class="font-medium">{{ $submittedByName ?? '—' }} @if ($expense->submitted_at) ({{ $expense->submitted_at }}) @endif</dd></div>
        <div class="flex justify-between gap-4">
            <dt class="text-charcoal/70">Approved by</dt>
            <dd class="font-medium">
                @if ($approvedByName)
                    {{ $approvedByName }} @if ($expense->approved_at) ({{ $expense->approved_at }}) @endif
                @elseif (! $expense->requires_approval && $expense->approved_at)
                    Below threshold ({{ Money::of((int) $expense->approval_threshold_applied)->format(false) }}) — no checker required
                @else
                    —
                @endif
            </dd>
        </div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Posted by</dt><dd class="font-medium">{{ $postedByName ?? '—' }} @if ($expense->posted_at) ({{ $expense->posted_at }}) @endif</dd></div>
        <div class="flex justify-between gap-4">
            <dt class="text-charcoal/70">Journal entry</dt>
            <dd class="font-medium font-mono">
                @if ($expense->journal_entry_id)
                    {{ $expense->piece_no ?? ('#'.$expense->journal_entry_id) }}
                @else
                    —
                @endif
            </dd>
        </div>
        @if ($expense->notes)
            <div class="sm:col-span-2 text-charcoal/70">{{ $expense->notes }}</div>
        @endif
    </dl>

    @if ($entryLines->isNotEmpty())
        <div class="rounded border border-sand bg-white p-4 text-sm print:hidden">
            <p class="mb-2 text-xs font-semibold uppercase text-charcoal/60">Ledger entry {{ $expense->piece_no }}</p>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-charcoal/60">
                        <th scope="col" class="py-1 pr-2">#</th>
                        <th scope="col" class="py-1 pr-2">Account</th>
                        <th scope="col" class="py-1 pr-2">Label</th>
                        <th scope="col" class="py-1 pr-2 text-right">Debit</th>
                        <th scope="col" class="py-1 text-right">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entryLines as $entryLine)
                        <tr class="border-t border-sand/60">
                            <td class="py-1 pr-2">{{ $entryLine->sequence }}</td>
                            <td class="py-1 pr-2 font-mono">{{ $entryLine->account_code }} {{ $entryLine->account_name }}</td>
                            <td class="py-1 pr-2">{{ $entryLine->label }}</td>
                            <td class="py-1 pr-2 text-right font-mono">{{ (int) $entryLine->debit > 0 ? Money::of((int) $entryLine->debit)->format(false) : '' }}</td>
                            <td class="py-1 text-right font-mono">{{ (int) $entryLine->credit > 0 ? Money::of((int) $entryLine->credit)->format(false) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Print-preview voucher --}}
    <div id="print-area" class="rounded border border-sand bg-white p-8 text-sm shadow-sm print:border-0 print:shadow-none">
        <div class="mb-6 flex items-start justify-between border-b border-sand pb-4">
            <div>
                <h2 class="text-lg font-bold text-charcoal">EXPENSE VOUCHER</h2>
                <p class="font-mono text-charcoal/70">{{ $expense->expense_no }}</p>
            </div>
            <div class="text-right">
                <p class="font-medium">{{ $expense->expense_date }}</p>
                <p class="text-charcoal/70">{{ $expense->currency }}</p>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-8">
            <div>
                <p class="mb-1 text-xs font-semibold uppercase text-charcoal/60">Paid to</p>
                <p class="font-medium">{{ $expense->payee_name }}</p>
                <p class="text-charcoal/70">{{ ucfirst($expense->payee_type) }}</p>
            </div>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase text-charcoal/60">Paid out of</p>
                <p class="font-mono">{{ $expense->treasury_code }}</p>
                <p class="text-charcoal/70">{{ $expense->treasury_name }}</p>
            </div>
        </div>

        <p class="mb-4"><span class="text-xs font-semibold uppercase text-charcoal/60">For:</span> {{ $expense->description }}</p>

        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-sand text-left text-xs text-charcoal/60">
                    <th scope="col" class="py-2 pr-2">#</th>
                    <th scope="col" class="py-2 pr-2">Label</th>
                    <th scope="col" class="py-2 pr-2">Account</th>
                    <th scope="col" class="py-2 pr-2">Analytic</th>
                    <th scope="col" class="py-2 pr-2">Tax</th>
                    <th scope="col" class="py-2 text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr class="border-b border-sand/60">
                        <td class="py-2 pr-2">{{ $line->line_no }}</td>
                        <td class="py-2 pr-2">{{ $line->label }}</td>
                        <td class="py-2 pr-2 font-mono">{{ $line->account_code }} {{ $line->account_name }}</td>
                        <td class="py-2 pr-2">{{ $line->analytic_label ?: '—' }}</td>
                        <td class="py-2 pr-2">{{ $line->tax_code ?: '—' }}</td>
                        <td class="py-2 text-right font-mono">{{ Money::of((int) $line->amount)->format(false) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-4 flex justify-end">
            <dl class="w-72 space-y-1 text-sm">
                <div class="flex justify-between border-t border-sand pt-1 font-semibold">
                    <dt>Total</dt>
                    <dd class="font-mono">{{ Money::of((int) $expense->total_amount)->format(true) }}</dd>
                </div>
            </dl>
        </div>

        <div class="mt-10 grid grid-cols-3 gap-8 text-xs text-charcoal/70">
            <div><p class="border-t border-charcoal/40 pt-1">Recorded by</p><p class="mt-1">{{ $createdByName ?? '' }}</p></div>
            <div><p class="border-t border-charcoal/40 pt-1">Approved by</p><p class="mt-1">{{ $approvedByName ?? '' }}</p></div>
            <div><p class="border-t border-charcoal/40 pt-1">Received by (payee)</p></div>
        </div>
    </div>
</div>
