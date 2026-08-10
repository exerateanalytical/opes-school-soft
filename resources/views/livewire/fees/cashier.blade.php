@php
    use App\Support\Money\Money;
@endphp

{{--
    Fee Collection (Cashier) - mockup panel 3 of the Jul 13 sheet.
    Left column: student search + selected-student card + fee breakdown.
    Right column: payment details panel + the collection form.

    Every figure on this screen is computed by the component from issued
    invoices and non-voided payments (04-fees §5 - balance is computed, never
    stored). Nothing here is invented; tiles whose backing data does not exist
    are simply not rendered.
--}}
<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.fees_screen.breadcrumb_dashboard') }}</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>{{ __('opes.fees_screen.breadcrumb_finance') }}</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.fees_screen.breadcrumb_cashier') }}</span>
            </li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <h1 class="min-w-0 text-xl font-semibold text-charcoal">{{ __('opes.fees_screen.cashier_title') }}</h1>
        <a href="{{ route('fees.invoices.index') }}"
           class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ __('opes.fees_screen.invoices_title') }}
        </a>
    </div>

    {{-- Success banner: the receipt number is the thing the cashier reads
         aloud and writes down, so it is the biggest text on the banner. --}}
    @if (is_string($receiptNo) && $receiptNo !== '')
        <div class="rounded border border-primary/40 bg-primary/10 p-4" role="status">
            <p class="text-sm font-medium text-primary">{{ __('opes.fees_screen.payment_recorded') }}</p>
            <p class="mt-1 text-xs uppercase tracking-wide text-charcoal/60">{{ __('opes.fees_screen.receipt_no') }}</p>
            <p class="font-mono text-2xl font-bold text-charcoal">{{ $receiptNo }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                @if ($selected !== null)
                    <a href="{{ route('fees.students.statement', ['student' => $selected['id']]) }}"
                       class="inline-flex items-center gap-1 text-sm font-medium text-primary underline hover:no-underline">
                        {{ __('opes.fees_screen.view_statement') }}
                    </a>
                @endif
                {{-- Phase 13 D3 (10-documents §10.1): the real receipt
                     template now exists - Print opens the A5 PDF in a new
                     tab; the request re-authorizes documents.print itself. --}}
                @if (is_int($lastPaymentId))
                    <a href="{{ route('fees.payments.receipt', ['payment' => $lastPaymentId]) }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 text-sm font-medium text-primary underline hover:no-underline">
                        {{ __('opes.fees_screen.print_receipt') }}
                    </a>
                @endif
            </div>
        </div>
    @endif

    {{-- Domain refusals (over-allocation, missing posting rule, closed
         period…) land here as a legible sentence, never a 500. --}}
    @if (is_string($errorMessage) && $errorMessage !== '')
        <div class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- ── Cash desk (04-fees §11.7 / §17.2) ─────────────────────────────
         The till. Everything the cashier needs to answer "is the money in
         the tin the money the system says should be in the tin": the float
         they declared, what this shift has collected, and what should
         therefore be there right now. Cash collection is refused until this
         panel says a session is open. --}}
    <section class="rounded border border-sand bg-white p-4" aria-labelledby="cash-desk-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="cash-desk-heading" class="text-sm font-semibold text-primary">Cash desk</h2>
                @if ($session === null)
                    <p class="mt-1 text-sm text-charcoal/70">
                        No session is open. Cash collection is blocked until you open one with its opening float.
                    </p>
                @else
                    <p class="mt-1 text-sm text-charcoal/70">
                        <span class="font-mono font-semibold text-charcoal">{{ $session['session_no'] }}</span>
                        · {{ $session['treasury_label'] }}
                        · opened {{ $session['opened_at'] }}
                    </p>
                @endif
            </div>

            @if ($canCollect)
                <div class="flex flex-wrap items-center gap-2">
                    @if ($session === null)
                        <button type="button" wire:click="toggleOpenSessionForm"
                                class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ $showOpenSessionForm ? 'Cancel' : 'Open session' }}
                        </button>
                    @else
                        {{-- The route is wired centrally (routes/web.php is
                             owned elsewhere); until it is, the button simply
                             is not rendered rather than fataling the screen. --}}
                        @if (Route::has('fees.cashdesk.show'))
                            <a href="{{ route('fees.cashdesk.show', ['session' => $session['id']]) }}"
                               class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                Close-out sheet
                            </a>
                        @endif
                        <button type="button" wire:click="toggleCloseSessionForm"
                                class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ $showCloseSessionForm ? 'Cancel' : 'Close session' }}
                        </button>
                    @endif
                </div>
            @endif
        </div>

        @if (is_string($sessionMessage) && $sessionMessage !== '')
            <p class="mt-3 rounded border border-primary/40 bg-primary/10 p-3 text-sm text-primary" role="status">{{ $sessionMessage }}</p>
        @endif

        @if (is_string($sessionError) && $sessionError !== '')
            <p class="mt-3 rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $sessionError }}</p>
        @endif

        @if ($session !== null)
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded border border-sand p-3">
                    <dt class="text-xs uppercase tracking-wide text-charcoal/60">Opening float</dt>
                    <dd class="mt-1 font-mono text-sm font-semibold text-charcoal">{{ Money::of($session['opening_float'])->format(false) }}</dd>
                </div>
                <div class="rounded border border-sand p-3">
                    <dt class="text-xs uppercase tracking-wide text-charcoal/60">Collections</dt>
                    <dd class="mt-1 font-mono text-sm font-semibold text-charcoal">{{ $session['collections'] }}</dd>
                </div>
                <div class="rounded border border-sand p-3">
                    <dt class="text-xs uppercase tracking-wide text-charcoal/60">Collected</dt>
                    <dd class="mt-1 font-mono text-sm font-semibold text-charcoal">{{ Money::of($session['collected'])->format(false) }}</dd>
                </div>
                <div class="rounded border border-sand p-3">
                    <dt class="text-xs uppercase tracking-wide text-charcoal/60">Expected in till</dt>
                    <dd class="mt-1 font-mono text-sm font-bold text-primary">{{ Money::of($session['expected'])->format(false) }}</dd>
                </div>
            </dl>
        @endif

        {{-- Open --}}
        @if ($canCollect && $session === null && $showOpenSessionForm)
            <form wire:submit="openSessionAction" class="mt-4 border-t border-sand pt-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label for="cashdesk-box" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Cash box</span>
                        <select id="cashdesk-box" wire:model="sessionTreasuryAccountId"
                                class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">{{ __('opes.ui.select_placeholder') }}</option>
                            @foreach ($cashBoxOptions as $boxOption)
                                <option value="{{ $boxOption['id'] }}">{{ $boxOption['label'] }}</option>
                            @endforeach
                        </select>
                        @error('sessionTreasuryAccountId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    <label for="cashdesk-float" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Opening float (FCFA)</span>
                        <input id="cashdesk-float" type="number" min="0" step="1" wire:model="openingFloat"
                               class="rounded border border-sand bg-white px-2 py-1.5 text-right font-mono text-sm text-charcoal"/>
                        @error('openingFloat')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>
                </div>

                <button type="submit" class="mt-3 rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                    Open the till
                </button>
            </form>
        @endif

        {{-- Close. Expected is shown but NOT editable: the system computes it
             from this session's own collections; the cashier declares only
             what they counted. That asymmetry is the control. --}}
        @if ($canCollect && $session !== null && $showCloseSessionForm)
            <form wire:submit="closeSessionAction" class="mt-4 border-t border-sand pt-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label for="cashdesk-counted" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Counted cash (FCFA)</span>
                        <input id="cashdesk-counted" type="number" min="0" step="1" wire:model="countedCash"
                               class="rounded border border-sand bg-white px-2 py-1.5 text-right font-mono text-sm text-charcoal"/>
                        @error('countedCash')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    <label for="cashdesk-reason" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Variance reason (required if it does not balance)</span>
                        <input id="cashdesk-reason" type="text" maxlength="400" wire:model="varianceReason"
                               class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
                        @error('varianceReason')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>
                </div>

                <p class="mt-2 text-xs text-charcoal/60">
                    Expected in the till: <span class="font-mono">{{ Money::of($session['expected'])->format() }}</span>
                    (opening float {{ Money::of($session['opening_float'])->format(false) }} + {{ $session['collections'] }} collections).
                </p>

                <button type="submit" class="mt-3 rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                    Close the till
                </button>
            </form>
        @endif
    </section>

    <div class="flex min-w-0 flex-col gap-4 lg:flex-row">
        {{-- ── Left: student search, card, breakdown ───────────────────── --}}
        <div class="min-w-0 flex-1 space-y-4">
            <section class="rounded border border-sand bg-white p-4">
                <label for="cashier-search" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.select_student') }}</span>
                    <input id="cashier-search" type="search" wire:model.live.debounce.400ms="search"
                           placeholder="{{ __('opes.fees_screen.search_placeholder') }}"
                           class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>

                @if ($search !== '' && $selected === null)
                    @if ($results === [])
                        <p class="mt-2 text-sm text-charcoal/60">{{ __('opes.fees_screen.search_no_results') }}</p>
                    @else
                        <ul class="mt-2 divide-y divide-sand rounded border border-sand">
                            @foreach ($results as $result)
                                <li wire:key="cashier-result-{{ $result['id'] }}">
                                    <button type="button" wire:click="selectStudent({{ $result['id'] }})"
                                            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-sand/50">
                                        <span class="min-w-0 truncate font-medium text-charcoal">{{ $result['name'] }}</span>
                                        <span class="shrink-0 font-mono text-xs text-charcoal/60">{{ $result['matricule'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </section>

            @if ($selected === null)
                <x-empty-state :message="__('opes.fees_screen.no_student_selected')"/>
            @else
                {{-- Selected-student card: initials avatar (photo_path is a
                     private-disk path with no serving controller yet, same
                     reasoning as the students list), matricule | class,
                     balance due in red. --}}
                <section class="rounded border border-sand bg-white p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-chrome-light text-sm font-semibold uppercase text-white">
                                {{ $selected['initials'] }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-base font-semibold text-charcoal">{{ $selected['name'] }}</p>
                                <p class="truncate text-xs text-charcoal/60">
                                    <span class="font-mono">{{ $selected['matricule'] }}</span>
                                    @if ($selected['class'] !== '')
                                        <span aria-hidden="true"> · </span>{{ $selected['class'] }}
                                    @endif
                                </p>
                                <p class="mt-1 text-sm">
                                    <span class="text-charcoal/60">{{ __('opes.fees_screen.balance_due') }}:</span>
                                    <span class="font-mono font-bold text-heritage-red">{{ Money::of($totals['balance'])->format() }}</span>
                                </p>
                            </div>
                        </div>
                        <button type="button" wire:click="clearSelection"
                                class="rounded p-1.5 text-charcoal/50 hover:bg-sand hover:text-charcoal"
                                title="{{ __('opes.ui.reset') }}">
                            <span class="sr-only">{{ __('opes.ui.reset') }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </div>
                </section>

                {{-- Fee Breakdown: one row per open invoice line, exactly what
                     the payment will be allocated against. --}}
                <section class="rounded border border-sand bg-white">
                    <h2 class="border-b border-sand px-4 py-2.5 text-sm font-semibold text-primary">
                        {{ __('opes.fees_screen.fee_breakdown') }}
                    </h2>
                    @if ($breakdown === [])
                        <p class="px-4 py-3 text-sm text-charcoal/60">{{ __('opes.fees_screen.no_open_invoices') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[28rem] border-collapse text-sm">
                                <thead class="border-b border-sand bg-sand/40 text-left">
                                    <tr>
                                        <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_invoice') }}</th>
                                        <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_description') }}</th>
                                        <th scope="col" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_amount') }}</th>
                                        <th scope="col" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_outstanding') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-sand">
                                    @foreach ($breakdown as $line)
                                        <tr wire:key="cashier-line-{{ $loop->index }}">
                                            <td class="px-4 py-2 font-mono text-xs text-charcoal/70">{{ $line['invoice_no'] }}</td>
                                            <td class="px-4 py-2 text-charcoal">{{ $line['description'] }}</td>
                                            <td class="px-4 py-2 text-right font-mono text-charcoal">{{ Money::of($line['amount'])->format(false) }}</td>
                                            <td class="px-4 py-2 text-right font-mono {{ $line['outstanding'] > 0 ? 'text-heritage-red' : 'text-charcoal/60' }}">{{ Money::of($line['outstanding'])->format(false) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="border-t border-sand bg-sand/30">
                                    <tr>
                                        <th scope="row" colspan="2" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.breakdown_total') }}</th>
                                        <td class="px-4 py-2 text-right font-mono font-semibold text-charcoal">{{ Money::of($totals['invoiced'])->format(false) }}</td>
                                        <td class="px-4 py-2 text-right font-mono font-semibold text-heritage-red">{{ Money::of($totals['balance'])->format(false) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </section>
            @endif
        </div>

        {{-- ── Right rail: payment details + collection form ────────────── --}}
        <aside class="w-full shrink-0 space-y-4 lg:w-80">
            <section class="rounded border border-sand bg-white p-4">
                <h2 class="text-sm font-semibold text-primary">{{ __('opes.fees_screen.payment_details') }}</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-2">
                        <dt class="text-charcoal/60">{{ __('opes.fees_screen.total_invoiced') }}</dt>
                        <dd class="font-mono text-charcoal">{{ $selected === null ? '—' : Money::of($totals['invoiced'])->format() }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-charcoal/60">{{ __('opes.fees_screen.total_paid') }}</dt>
                        <dd class="font-mono text-charcoal">{{ $selected === null ? '—' : Money::of($totals['paid'])->format() }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 border-t border-sand pt-2">
                        <dt class="font-medium text-charcoal">{{ __('opes.fees_screen.balance_due') }}</dt>
                        <dd class="font-mono font-bold text-heritage-red">{{ $selected === null ? '—' : Money::of($totals['balance'])->format() }}</dd>
                    </div>
                </dl>
            </section>

            <form wire:submit="collect" class="rounded border border-sand bg-white p-4">
                <div class="space-y-3">
                    <label for="cashier-amount" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.payment_amount') }}</span>
                        <input id="cashier-amount" type="number" min="1" step="1" wire:model="amount"
                               @disabled($selected === null)
                               class="rounded border border-sand bg-white px-2 py-1.5 text-right font-mono text-sm text-charcoal disabled:bg-sand/40"/>
                        @error('amount')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    <label for="cashier-method" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.payment_method') }}</span>
                        {{-- .live so choosing a method immediately re-defaults
                             the "Received into" float below; a deferred bind
                             would resolve the default only at submit and
                             silently overwrite an explicit override. --}}
                        <select id="cashier-method" wire:model.live="method" @disabled($selected === null)
                                class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40">
                            @foreach ($methodOptions as $option)
                                <option value="{{ $option }}">{{ __('opes.fees_screen.method_'.$option) }}</option>
                            @endforeach
                        </select>
                        @error('method')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    {{-- "Received into" (02-accounting §2/§11.3): the class-5
                         account the money really landed in. Defaulted from the
                         method, overridable - a school running MTN and Orange
                         side by side says which float took this note, so each
                         one can be reconciled against its own operator
                         statement instead of dissolving into "cash in hand". --}}
                    <label for="cashier-treasury" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.treasury_account') }}</span>
                        <select id="cashier-treasury" wire:model="treasuryAccountId" @disabled($selected === null)
                                class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40">
                            <option value="">{{ __('opes.ui.select_placeholder') }}</option>
                            @foreach ($treasuryOptions as $treasuryOption)
                                <option value="{{ $treasuryOption['id'] }}">{{ $treasuryOption['label'] }}</option>
                            @endforeach
                        </select>
                        @error('treasuryAccountId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    <label for="cashier-reference" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.reference') }}</span>
                        <input id="cashier-reference" type="text" wire:model="reference"
                               placeholder="{{ __('opes.fees_screen.reference_placeholder') }}"
                               @disabled($selected === null)
                               class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40"/>
                        @error('reference')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    <div class="flex items-center gap-2">
                        <button type="submit" @disabled($selected === null || ! $canCollect)
                                @if (! $canCollect) title="{{ __('opes.nav.nav_disabled_title') }}" @endif
                                class="flex-1 rounded border border-primary bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50">
                            {{ __('opes.fees_screen.collect_payment') }}
                        </button>
                        {{-- The mockup's printer button now opens the real
                             statement PDF (10-documents §10.3); the dedicated
                             receipt template's own Print link is in the
                             success banner above, once a payment exists. --}}
                        @if ($selected !== null)
                            <a href="{{ route('fees.students.statement.print', ['student' => $selected['id']]) }}" target="_blank" rel="noopener"
                               title="{{ __('opes.fees_screen.view_statement') }}"
                               class="rounded border border-sand p-2 text-charcoal/60 hover:border-primary/50 hover:text-primary">
                                <span class="sr-only">{{ __('opes.fees_screen.view_statement') }}</span>
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v7H6v-7z"/></svg>
                            </a>
                        @endif
                    </div>
                </div>
            </form>

            {{-- Void a payment (04-fees §11.5): the cashier screen carries no
                 payments list to hang a per-row action off, so voiding is a
                 standalone lookup-by-receipt toggle form, gated fee.void and
                 requiring a reason - never a silent correction. --}}
            @if ($canVoid)
                <section class="rounded border border-sand bg-white p-4">
                    <button type="button" wire:click="toggleVoidForm"
                            class="flex w-full items-center justify-between text-left text-sm font-semibold text-heritage-red">
                        <span>{{ __('opes.fees_screen.void_payment') }}</span>
                        <span aria-hidden="true">{{ $showVoidForm ? '−' : '+' }}</span>
                    </button>

                    @if ($voidStatus !== '')
                        <p class="mt-2 text-sm text-primary" role="status">{{ $voidStatus }}</p>
                    @endif

                    @if ($showVoidForm)
                        <form wire:submit="voidPayment" class="mt-3 space-y-3">
                            <label for="cashier-void-receipt" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.receipt_no') }}</span>
                                <input id="cashier-void-receipt" type="text" wire:model="voidReceiptNo"
                                       class="rounded border border-sand bg-white px-2 py-1.5 font-mono text-sm text-charcoal"/>
                                @error('voidReceiptNo')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                            </label>

                            <label for="cashier-void-reason" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.void_reason') }}</span>
                                <select id="cashier-void-reason" wire:model="voidReasonType"
                                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                                    <option value="">{{ __('opes.ui.select_placeholder') }}</option>
                                    @foreach ($voidReasonOptions as $option)
                                        <option value="{{ $option }}">{{ __('opes.fees_screen.void_reason_'.$option) }}</option>
                                    @endforeach
                                </select>
                                @error('voidReasonType')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                            </label>

                            <label for="cashier-void-note" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.void_reason_note') }}</span>
                                <textarea id="cashier-void-note" wire:model="voidReasonNote" rows="3"
                                          class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"></textarea>
                                @error('voidReasonNote')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                            </label>

                            <button type="submit"
                                    class="w-full rounded border border-heritage-red bg-heritage-red px-3 py-2 text-sm font-semibold text-white hover:bg-heritage-red/90">
                                {{ __('opes.fees_screen.void_payment') }}
                            </button>
                        </form>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
