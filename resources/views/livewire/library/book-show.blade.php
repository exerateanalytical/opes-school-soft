@php
    $copyTone = [
        'available' => 'ok',
        'issued' => 'amber',
        'reserved' => 'amber',
        'lost' => 'red',
        'damaged' => 'red',
        'withdrawn' => 'red',
        'in_repair' => 'amber',
    ];

    $issueTone = [
        'open' => 'amber',
        'overdue' => 'red',
        'returned' => 'ok',
        'lost' => 'red',
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
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $book->title }}</span>
            </li>
        </ol>
    </nav>

    {{-- ── Header summary ─────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-sand bg-white p-4 shadow-sm sm:p-5">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-charcoal">{{ $book->title }}</h1>
                <x-status-pill :status="$book->is_archived ? 'red' : 'ok'" :label="$book->is_archived ? 'Archived' : 'Active'"/>
            </div>
            <p class="mt-1 text-sm text-charcoal/70">{{ $book->author }} · {{ $categoryName }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">ISBN</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $book->isbn ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Copies (total / available)</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $copiesTotal }} / {{ $copiesAvailable }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('library.index') }}"
               class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Back to library
            </a>
        </div>
    </div>

    {{-- ── Copies ────────────────────────────────────────────────────── --}}
    <section class="rounded-lg border border-sand bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Copies</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-sand text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                        <th class="px-2 py-2">Accession No.</th>
                        <th class="px-2 py-2">Barcode</th>
                        <th class="px-2 py-2">Shelf</th>
                        <th class="px-2 py-2">Condition</th>
                        <th class="px-2 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand/70">
                    @forelse ($copies as $copy)
                        <tr wire:key="copy-{{ $copy->id }}">
                            <td class="px-2 py-2 font-medium text-charcoal">{{ $copy->accession_no }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $copy->barcode }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $copy->shelf_code ?? '—' }}</td>
                            <td class="px-2 py-2 capitalize text-charcoal/80">{{ $copy->condition }}</td>
                            <td class="px-2 py-2">
                                <x-status-pill :status="$copyTone[$copy->status] ?? 'ok'" :label="$label($copy->status)"/>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-2 py-3 text-center text-charcoal/50">No copies recorded for this title.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Circulation history ──────────────────────────────────────── --}}
    <section class="rounded-lg border border-sand bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Circulation history</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-sand text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                        <th class="px-2 py-2">Issue No.</th>
                        <th class="px-2 py-2">Copy</th>
                        <th class="px-2 py-2">Member</th>
                        <th class="px-2 py-2">Issued</th>
                        <th class="px-2 py-2">Due</th>
                        <th class="px-2 py-2">Returned</th>
                        <th class="px-2 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand/70">
                    @forelse ($circulationHistory as $row)
                        <tr wire:key="issue-{{ $row->id }}">
                            <td class="px-2 py-2 font-medium text-charcoal">{{ $row->issue_no }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->accession_no }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->member_no }}{{ $row->external_name ? ' - '.$row->external_name : '' }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->issued_on }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->due_on }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->returned_on ?? '—' }}</td>
                            <td class="px-2 py-2">
                                <x-status-pill :status="$issueTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-2 py-3 text-center text-charcoal/50">No circulation history for this title.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Print Book Label ─────────────────────────────────────────── --}}
    <section class="rounded-lg border border-sand bg-white p-4 shadow-sm sm:p-5 print:border-0 print:shadow-none" id="book-label-section">
        <div class="flex items-center justify-between gap-3 print:hidden">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Print book label</h2>
            <div class="flex items-center gap-2">
                <button type="button" onclick="window.print()"
                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Print
                </button>
                <button type="button" wire:click="exportBookLabelPdf"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Export PDF
                </button>
            </div>
        </div>

        <div class="mt-4 mx-auto max-w-sm rounded-lg border-2 border-charcoal/20 p-4 print:mx-0 print:max-w-none print:border-black">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/50">Book Label</p>
            <p class="mt-1 text-lg font-bold text-charcoal">{{ $book->title }}</p>
            <p class="text-sm text-charcoal">{{ $book->author }}</p>
            <dl class="mt-3 space-y-1 text-xs text-charcoal/80">
                <div class="flex justify-between gap-2">
                    <dt>Category</dt>
                    <dd class="font-medium">{{ $categoryName }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>ISBN</dt>
                    <dd class="font-medium">{{ $book->isbn ?? '—' }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-charcoal/50">Accession numbers</p>
            <ul class="mt-1 space-y-0.5 text-xs text-charcoal/80">
                @forelse ($copies as $copy)
                    <li>{{ $copy->accession_no }}</li>
                @empty
                    <li>—</li>
                @endforelse
            </ul>
        </div>
    </section>

</div>
