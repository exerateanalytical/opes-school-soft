@php
    $examTone = [
        'planned' => 'amber',
        'scheduled' => 'ok',
        'in_progress' => 'ok',
        'marked' => 'ok',
        'cancelled' => 'red',
    ];

    $examLabel = [
        'planned' => 'Planned',
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'marked' => 'Marked',
        'cancelled' => 'Cancelled',
    ];

    $roleLabel = [
        'chief' => 'Chief',
        'assistant' => 'Assistant',
    ];

    $endsAt = $exam->endsAt();
@endphp

<div class="min-w-0 space-y-4">

    {{-- ── Breadcrumb ─────────────────────────────────────────────────── --}}
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <a href="{{ route('assessment.examinations.index') }}" class="hover:text-primary">Examinations</a>
            </li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $header?->subject_name ?? 'Exam' }}</span>
            </li>
        </ol>
    </nav>

    {{-- ── Header card ────────────────────────────────────────────────── --}}
    <section aria-label="Exam details" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold text-charcoal">{{ $header?->subject_name ?? '—' }}</h1>
                <p class="mt-1 text-sm text-charcoal/70">{{ $header?->class_group_name ?? '—' }}</p>
            </div>
            <x-status-pill :status="$examTone[$exam->status] ?? 'amber'" :label="$examLabel[$exam->status] ?? $exam->status"/>
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-3">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-charcoal/50">Date</dt>
                <dd class="mt-0.5 text-sm text-charcoal">{{ $exam->scheduled_on->toDateString() }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-charcoal/50">Time</dt>
                <dd class="mt-0.5 text-sm text-charcoal">{{ substr($exam->starts_at, 0, 5) }} &ndash; {{ substr($endsAt, 0, 5) }} ({{ $exam->duration_minutes }} min)</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-charcoal/50">Room</dt>
                <dd class="mt-0.5 text-sm text-charcoal">
                    @if ($header?->room_name)
                        {{ $header->room_code }} &ndash; {{ $header->room_name }}
                    @else
                        <span class="text-charcoal/50">Not assigned</span>
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    {{-- ── Invigilators ───────────────────────────────────────────────── --}}
    <section aria-label="Invigilators" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Invigilators</h2>

        @if ($invigilators->isEmpty())
            <p class="mt-2 text-sm text-charcoal/60">No invigilators have been assigned to this sitting yet.</p>
        @else
            <ul class="mt-3 divide-y divide-border-primary">
                @foreach ($invigilators as $invigilator)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <span class="text-sm text-charcoal">{{ trim($invigilator->first_name.' '.$invigilator->last_name) }}</span>
                        <x-status-pill :status="$invigilator->role === 'chief' ? 'amber' : 'ok'" :label="$roleLabel[$invigilator->role] ?? $invigilator->role"/>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ── Seating summary ────────────────────────────────────────────── --}}
    <section aria-label="Seating summary" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Seating</h2>

        @if ($seating->isEmpty())
            <p class="mt-2 text-sm text-charcoal/60">Seating has not been generated for this sitting yet.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[24rem] text-left text-sm">
                    <thead>
                        <tr class="text-xs font-semibold uppercase tracking-wide text-charcoal/50">
                            <th scope="col" class="py-1.5 pr-4">Room</th>
                            <th scope="col" class="py-1.5 pr-4 text-right">Seats Filled</th>
                            <th scope="col" class="py-1.5 text-right">Capacity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-primary">
                        @foreach ($seating as $room)
                            <tr>
                                <td class="py-1.5 pr-4 text-charcoal">{{ $room->room_code }} &ndash; {{ $room->room_name }}</td>
                                <td class="py-1.5 pr-4 text-right tabular-nums text-charcoal/80">{{ $room->seats_filled }}</td>
                                <td class="py-1.5 text-right tabular-nums text-charcoal/80">{{ $room->room_capacity }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ── Print preview: Exam Slip/Notice ───────────────────────────────
         An on-screen preview, not a PDF download (there is no snapshot-backed
         RenderDocument template for this yet) — printed via the browser's own
         print dialog, `print:hidden` hides the chrome and `print:block` on the
         slip is the only thing left on the printed page (see the
         `@media print` rules below). --}}
    <section aria-label="Exam slip preview" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5 print:hidden">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-charcoal">Exam Slip / Notice</h2>
            <button type="button" onclick="window.print()"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                Print
            </button>
        </div>

        <div id="exam-slip-preview" class="mt-4 rounded border border-dashed border-border-primary p-4">
            <p class="text-center text-sm font-semibold uppercase tracking-wide text-charcoal">Exam Slip</p>
            <dl class="mt-3 space-y-1.5 text-sm text-charcoal">
                <div class="flex justify-between gap-3">
                    <dt class="text-charcoal/60">Subject</dt>
                    <dd class="font-medium">{{ $header?->subject_name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-charcoal/60">Class</dt>
                    <dd class="font-medium">{{ $header?->class_group_name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-charcoal/60">Date</dt>
                    <dd class="font-medium">{{ $exam->scheduled_on->toDateString() }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-charcoal/60">Time</dt>
                    <dd class="font-medium">{{ substr($exam->starts_at, 0, 5) }} &ndash; {{ substr($endsAt, 0, 5) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-charcoal/60">Duration</dt>
                    <dd class="font-medium">{{ $exam->duration_minutes }} minutes</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-charcoal/60">Room</dt>
                    <dd class="font-medium">{{ $header?->room_name ? $header->room_code.' – '.$header->room_name : 'Not assigned' }}</dd>
                </div>
            </dl>
        </div>
    </section>

    {{-- The printed page shows only the slip: everything else on this
         screen is `print:hidden`, and this clone renders `hidden` on screen
         but `print:block` on paper, so `window.print()` never captures the
         breadcrumb, tabs or buttons around it. --}}
    <div class="hidden print:block">
        <p class="text-center text-lg font-semibold uppercase tracking-wide">Exam Slip</p>
        <dl class="mx-auto mt-4 max-w-md space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt>Subject</dt><dd>{{ $header?->subject_name ?? '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Class</dt><dd>{{ $header?->class_group_name ?? '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Date</dt><dd>{{ $exam->scheduled_on->toDateString() }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Time</dt><dd>{{ substr($exam->starts_at, 0, 5) }} &ndash; {{ substr($endsAt, 0, 5) }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Duration</dt><dd>{{ $exam->duration_minutes }} minutes</dd></div>
            <div class="flex justify-between gap-3"><dt>Room</dt><dd>{{ $header?->room_name ? $header->room_code.' – '.$header->room_name : 'Not assigned' }}</dd></div>
        </dl>
    </div>
</div>
