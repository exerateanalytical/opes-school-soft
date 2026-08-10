@php
    $memberTone = [
        'active' => 'ok',
        'suspended' => 'amber',
        'expired' => 'red',
        'closed' => 'red',
    ];

    $issueTone = [
        'open' => 'amber',
        'overdue' => 'red',
        'returned' => 'ok',
        'lost' => 'red',
        'written_off' => 'red',
    ];

    $fineTone = [
        'assessed' => 'amber',
        'invoiced' => 'amber',
        'paid' => 'ok',
        'waived' => 'ok',
        'written_off' => 'red',
    ];

    $label = fn (string $value): string => ucfirst(str_replace('_', ' ', $value));
@endphp

<div class="min-w-0 space-y-4">

    {{-- ── Breadcrumb ─────────────────────────────────────────────────── --}}
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <a href="{{ route('library.index') }}" class="hover:text-primary">Library</a>
            </li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $member->member_no }}</span>
            </li>
        </ol>
    </nav>

    {{-- ── Header summary ─────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-charcoal">{{ $displayName }}</h1>
                <x-status-pill :status="$memberTone[$member->status->value] ?? 'ok'" :label="$label($member->status->value)"/>
            </div>
            <p class="mt-1 text-sm text-charcoal/70">Member {{ $member->member_no }} · {{ $className }} · {{ ucfirst($member->member_type->value) }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Active issues</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $activeIssuesCount }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Outstanding fines</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ number_format($outstandingFinesTotal / 100, 2) }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('library.index') }}"
               class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Back to library
            </a>
        </div>
    </div>

    {{-- ── Active issues ─────────────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Active issues</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-border-primary text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                        <th class="px-2 py-2">Issue No.</th>
                        <th class="px-2 py-2">Book</th>
                        <th class="px-2 py-2">Copy</th>
                        <th class="px-2 py-2">Issued</th>
                        <th class="px-2 py-2">Due</th>
                        <th class="px-2 py-2">Renewals</th>
                        <th class="px-2 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary/70">
                    @forelse ($activeIssues as $row)
                        <tr wire:key="active-{{ $row->id }}">
                            <td class="px-2 py-2 font-medium text-charcoal">{{ $row->issue_no }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->book_title }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->accession_no }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->issued_on }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->due_on }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ $row->renewal_count }}</td>
                            <td class="px-2 py-2">
                                <x-status-pill :status="$issueTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-2 py-3 text-center text-charcoal/50">No active issues for this member.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Circulation history ──────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Circulation history</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-border-primary text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                        <th class="px-2 py-2">Issue No.</th>
                        <th class="px-2 py-2">Book</th>
                        <th class="px-2 py-2">Copy</th>
                        <th class="px-2 py-2">Issued</th>
                        <th class="px-2 py-2">Due</th>
                        <th class="px-2 py-2">Returned</th>
                        <th class="px-2 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary/70">
                    @forelse ($circulationHistory as $row)
                        <tr wire:key="hist-{{ $row->id }}">
                            <td class="px-2 py-2 font-medium text-charcoal">{{ $row->issue_no }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->book_title }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->accession_no }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->issued_on }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->due_on }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->returned_on ?? '—' }}</td>
                            <td class="px-2 py-2">
                                <x-status-pill :status="$issueTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-2 py-3 text-center text-charcoal/50">No circulation history for this member.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Outstanding fines ────────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Fines</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-border-primary text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                        <th class="px-2 py-2">Fine No.</th>
                        <th class="px-2 py-2">Type</th>
                        <th class="px-2 py-2">Assessed</th>
                        <th class="px-2 py-2">Amount</th>
                        <th class="px-2 py-2">Waived</th>
                        <th class="px-2 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary/70">
                    @forelse ($fines as $row)
                        <tr wire:key="fine-{{ $row->id }}">
                            <td class="px-2 py-2 font-medium text-charcoal">{{ $row->fine_no }}</td>
                            <td class="px-2 py-2 capitalize text-charcoal/80">{{ $row->fine_type }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->assessed_on }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ number_format($row->amount / 100, 2) }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ number_format($row->waived_amount / 100, 2) }}</td>
                            <td class="px-2 py-2">
                                <x-status-pill :status="$fineTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-2 py-3 text-center text-charcoal/50">No fines recorded for this member.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Print Library Card ───────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5 print:border-0 print:shadow-none" id="library-card-section">
        <div class="flex items-center justify-between gap-3 print:hidden">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Print library card</h2>
            <div class="flex items-center gap-2">
                <button type="button" onclick="window.print()"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Print
                </button>
                <button type="button" wire:click="exportLibraryCardPdf"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Export PDF
                </button>
            </div>
        </div>

        <div class="mt-4 mx-auto max-w-sm rounded-lg border-2 border-charcoal/20 p-4 print:mx-0 print:max-w-none print:border-black">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/50">Library Card</p>
            <div class="mt-2 flex items-center gap-3">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded border border-dashed border-charcoal/30 text-[10px] text-charcoal/40">
                    Photo
                </div>
                <div>
                    <p class="text-lg font-bold text-charcoal">{{ $displayName }}</p>
                    <p class="text-sm text-charcoal">{{ $member->member_no }}</p>
                </div>
            </div>
            <dl class="mt-3 space-y-1 text-xs text-charcoal/80">
                <div class="flex justify-between gap-2">
                    <dt>Membership class</dt>
                    <dd class="font-medium">{{ $className }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>Joined on</dt>
                    <dd class="font-medium">{{ $member->joined_on }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>Expires on</dt>
                    <dd class="font-medium">{{ $member->expires_on ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    </section>

</div>
