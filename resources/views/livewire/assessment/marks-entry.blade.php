{{--
    ═══════════════════════════════════════════════════════════════════════════
    Marks entry — docs/specs/01-assessment.md §17.
    Mockup: `frontend images/Results management.png`.
    ═══════════════════════════════════════════════════════════════════════════

    ── Why this screen does not compose x-list-screen ────────────────────────

    x-list-screen requires a LengthAwarePaginator. This grid must not paginate:
    a paginated grid either sends a request per page (the thing §17 exists to
    prevent) or loses the rows typed on page 1. So the screen composes the same
    vocabulary by hand — breadcrumb, title, KPI strip, a filter bar ending in
    Filter | Reset, one table — and is bounded by the class group instead.

    ── Not one wire: binding on a cell ───────────────────────────────────────

    §17: "Keystrokes mutate a local Alpine store only. No Livewire round-trip
    per cell." Everything below the filter bar is Alpine. The server sees the
    grid twice: on load, and on save. A `wire:model` on a score input would
    silently reintroduce the 124-request behaviour, so its ABSENCE is asserted
    by T21 alongside the batch count.

    ── What the mockup shows that this screen does not ───────────────────────

    "Results management.png" also carries an Overall Performance Trend chart, a
    Top Performing Students rail, a Recent Activities feed and a Grade
    Distribution bar per class. None of them is rendered here:

      · the trend chart needs a weekly time series that no Phase 3 table
        records — `class_statistics` is per (period, class group), not per week;
      · Top Performing Students and Grade Distribution need computed period
        results, which exist only AFTER `ComputePeriodResults` has run for a
        published period, and this screen is where marks are still being TYPED;
      · Recent Activities needs a per-screen activity feed that Assessment does
        not have (`audit_logs` is not a user-facing feed).

    Rendering any of them from what is on this page would mean inventing
    numbers on the screen a teacher trusts most. They are omitted, not faked.
--}}

@php
    use App\Modules\Assessment\Domain\MarkState;

    // Conflicts keyed by mark id, so a row can show its own inline.
    $conflictsByMark = [];
    foreach ($conflicts as $conflict) {
        $conflictsByMark[$conflict['mark_id']] = $conflict;
    }

    $requiresReason = array_values(array_map(
        static fn (MarkState $s): string => $s->value,
        array_filter(MarkState::cases(), static fn (MarkState $s): bool => $s->requiresReason()),
    ));

    $scopeChosen = $classGroup > 0 && $allocation > 0 && $period > 0 && $component > 0;
@endphp

<div class="min-w-0 space-y-4">

    {{-- ── Breadcrumb ───────────────────────────────────────────────────── --}}
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.assessment_screen.breadcrumb_dashboard') }}</li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">
                    {{ __('opes.assessment_screen.breadcrumb_marks') }}
                </span>
            </li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <h1 class="min-w-0 text-xl font-semibold text-charcoal">
            {{ __('opes.assessment_screen.title') }}
        </h1>
    </div>

    {{-- ── The scope selector. Four controls, four round trips a session —
         not 124. Each list is derived from the marks this actor may enter
         (§7.5), so the select can never offer a scope the grid refuses. --}}
    <section aria-label="{{ __('opes.ui.filters') }}"
             class="rounded border border-border-primary bg-white p-3">
        <div class="flex flex-wrap items-end gap-3">
            <label for="marks-class-group" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium uppercase tracking-wide text-charcoal/60">
                    {{ __('opes.assessment_screen.scope_class_group') }}
                </span>
                <select id="marks-class-group" wire:model.live="classGroup"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="0">{{ __('opes.assessment_screen.choose') }}</option>
                    @foreach ($classGroupOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <label for="marks-allocation" class="flex min-w-[13rem] flex-col gap-1">
                <span class="text-xs font-medium uppercase tracking-wide text-charcoal/60">
                    {{ __('opes.assessment_screen.scope_allocation') }}
                </span>
                <select id="marks-allocation" wire:model.live="allocation"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="0">{{ __('opes.assessment_screen.choose') }}</option>
                    @foreach ($allocationOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <label for="marks-period" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium uppercase tracking-wide text-charcoal/60">
                    {{ __('opes.assessment_screen.scope_period') }}
                </span>
                <select id="marks-period" wire:model.live="period"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="0">{{ __('opes.assessment_screen.choose') }}</option>
                    @foreach ($periodOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <label for="marks-component" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium uppercase tracking-wide text-charcoal/60">
                    {{ __('opes.assessment_screen.scope_component') }}
                </span>
                <select id="marks-component" wire:model.live="component"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="0">{{ __('opes.assessment_screen.choose') }}</option>
                    @foreach ($componentOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <div class="ml-auto flex items-center gap-2">
                <button type="button" wire:click="$refresh"
                        class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    {{ __('opes.ui.filter') }}
                </button>
                <button type="button" wire:click="resetFilters"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    {{ __('opes.ui.reset') }}
                </button>
            </div>
        </div>
    </section>

    @if ($problem !== '')
        <p role="alert"
           class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red">
            {{ $problem }}
        </p>
    @endif

    @if ($notice !== '')
        <p role="status"
           class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary">
            {{ $notice }}
        </p>
    @endif

    {{-- ── T16 / §7.7. The conflict is a CONVERSATION, not an exception. It
         names who changed the mark and what they set it to, and leaves the
         teacher's own value on screen so keep-mine is a real choice. --}}
    @if ($conflicts !== [])
        <section role="alert" aria-labelledby="marks-conflict-heading"
                 class="rounded border border-heritage-yellow/70 bg-heritage-yellow/15 p-3">
            <h2 id="marks-conflict-heading" class="text-sm font-semibold text-charcoal">
                {{ trans_choice('opes.assessment_screen.conflict_heading', count($conflicts), ['count' => count($conflicts)]) }}
            </h2>
            <p class="mt-1 text-xs text-charcoal/70">
                {{ __('opes.assessment_screen.conflict_explainer') }}
            </p>
            <ul class="mt-2 space-y-1 text-sm text-charcoal">
                @foreach ($conflicts as $conflict)
                    <li class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium">{{ $conflict['their_actor_name'] }}</span>
                        <span>{{ __('opes.assessment_screen.conflict_set_to') }}</span>
                        <span class="rounded bg-white px-1.5 py-0.5 font-mono text-xs font-semibold">
                            {{ $conflict['their_score'] ?? __('opes.assessment_screen.state_'.$conflict['their_state']) }}
                        </span>
                        @if ($conflict['changed_at'] !== null)
                            <span class="text-xs text-charcoal/60">{{ $conflict['changed_at'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @unless ($scopeChosen)
        <x-empty-state :message="__('opes.assessment_screen.choose_scope')"/>
    @else

    <div
        x-data="{
            rows: @js($grid),
            conflicts: @js($conflictsByMark),
            max: @js($effectiveMax === null ? null : (float) $effectiveMax),
            reasonStates: @js($requiresReason),

            {{-- Translated strings reach Alpine through @js, never through a
                 quoted {{ }} inside a JS literal: `{{ }}` escapes an
                 apostrophe to &#039;, the HTML parser decodes it back to ',
                 and a natural French label - "Élève dispensé d'EPS" - would
                 then terminate the JS string and break the whole grid. --}}
            labels: @js([
                'draft' => __('opes.assessment_screen.workflow_draft'),
                'submitted' => __('opes.assessment_screen.workflow_submitted'),
                'validated' => __('opes.assessment_screen.workflow_validated'),
                'reason' => __('opes.assessment_screen.col_reason'),
            ]),

            {{-- A validated row's cells are disabled for a REASON, and a
                 control that refuses without saying why reads as broken. The
                 sentence travels through @js for the same apostrophe reason
                 as the labels above. --}}
            lockedHint: @js(__('opes.assessment_screen.locked_hint')),

            {{-- State-button titles, keyed by state, so a locked cell can swap
                 its tooltip for the lock explanation without interpolating a
                 translated label into a JS string literal. --}}
            stateTitles: @js(array_column($stateControls, 'label', 'value')),

            baseline: {},
            autosaveTimer: null,

            init() {
                this.rebaseline(this.rows.map(r => r.mark_id));

                // §17's autosave-on-navigate-away, using the same batch call
                // and the same idempotency key as the button.
                window.addEventListener('beforeunload', () => {
                    if (this.dirty().length > 0) { this.save(); }
                });

                // The server tells us which rows landed. Saved rows are
                // re-baselined; CONFLICTING rows stay dirty on purpose, so the
                // teacher's typed value is still on screen next to the other
                // party's and keep-mine is one click.
                this.$wire.on('marks-batch-saved', (event) => {
                    this.rebaseline(event.saved ?? []);
                });
            },

            fingerprint(row) {
                return JSON.stringify([row.score ?? '', row.state, row.comment ?? '']);
            },

            rebaseline(ids) {
                this.rows.forEach(row => {
                    if (ids.includes(row.mark_id)) {
                        this.baseline[row.mark_id] = this.fingerprint(row);
                    }
                });
            },

            dirty() {
                return this.rows.filter(row => this.baseline[row.mark_id] !== this.fingerprint(row));
            },

            locked(row) {
                return row.workflow_state === 'validated';
            },

            needsReason(row) {
                return this.reasonStates.includes(row.state);
            },

            outOfRange(row) {
                if (row.state !== 'scored' || row.score === null || row.score === '') return false;
                const value = Number(row.score);
                return Number.isNaN(value) || value < 0 || (this.max !== null && value > this.max);
            },

            // Typing a number IS the scored state. §6.4: an absent child and a
            // zero are different facts, so the state always travels with the
            // number and is never inferred from it being blank.
            onInput(row) {
                if (row.score !== null && String(row.score).trim() !== '') {
                    row.state = 'scored';
                } else if (row.state === 'scored') {
                    row.state = 'pending';
                }
                this.scheduleAutosave();
            },

            setState(row, state) {
                if (this.locked(row)) return;
                row.state = state;
                if (state !== 'scored') { row.score = null; }
                this.scheduleAutosave();
            },

            clearRow(row) {
                if (this.locked(row)) return;
                row.score = null;
                row.comment = null;
                // Del restores `pending` — "nobody has said what happened
                // here" — rather than writing a zero (§6.4).
                row.state = 'pending';
                this.scheduleAutosave();
            },

            focusCell(index) {
                const el = this.$root.querySelector('[data-mark-index=\'' + index + '\']');
                if (el) { el.focus(); el.select(); }
            },

            onKey(event, index, row) {
                const key = event.key;

                if (key === 'ArrowDown' || key === 'Enter') {
                    event.preventDefault(); this.focusCell(index + 1); return;
                }
                if (key === 'ArrowUp') {
                    event.preventDefault(); this.focusCell(index - 1); return;
                }
                if (key === 'Delete') {
                    event.preventDefault(); this.clearRow(row); return;
                }
                // §17's single-key state shortcuts. A teacher marks 62 rows
                // without reaching for the mouse.
                if (key === 'a') { event.preventDefault(); this.setState(row, 'absent_unjustified'); return; }
                if (key === 'j') { event.preventDefault(); this.setState(row, 'absent_justified'); return; }
                if (key === 'x') { event.preventDefault(); this.setState(row, 'exempt'); return; }
            },

            scheduleAutosave() {
                clearTimeout(this.autosaveTimer);
                this.autosaveTimer = setTimeout(() => this.save(), 8000);
            },

            // ONE request for the whole grid (§17, T21).
            save() {
                clearTimeout(this.autosaveTimer);
                const changed = this.dirty();
                if (changed.length === 0) return;

                this.$wire.saveBatch(changed.map(row => ({
                    mark_id: row.mark_id,
                    version: row.version,
                    state: row.state,
                    score: row.state === 'scored' ? row.score : null,
                    comment: row.comment,
                })));
            },

            get enteredCount() { return this.rows.filter(r => r.state !== 'pending').length; },
            get pendingCount() { return this.rows.filter(r => r.state === 'pending').length; },
            get rangeCount() { return this.rows.filter(r => this.outOfRange(r)).length; },
            get dirtyCount() { return this.dirty().length; },
            get classMean() {
                const scored = this.rows.filter(r => r.state === 'scored' && r.score !== null && r.score !== '');
                if (scored.length === 0) return null;
                const sum = scored.reduce((t, r) => t + Number(r.score), 0);
                return (sum / scored.length).toFixed(2);
            },
        }"
        class="space-y-4"
    >
        {{-- ── KPI strip. Every figure is computed from what is ON THIS GRID,
             live, with no round trip. The mockup's school-wide tiles (Results
             Published, Pass Rate, Distinctions) are absent because they need
             computed, published period results and this screen is where marks
             are still being typed. --}}
        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <div class="grid min-w-max grid-cols-2 gap-3 sm:min-w-0 md:grid-cols-4">
                <x-kpi-card :label="__('opes.assessment_screen.kpi_entered')" icon-bg="bg-primary">
                    <x-slot:value><span x-text="enteredCount"></span></x-slot:value>
                </x-kpi-card>

                <x-kpi-card :label="__('opes.assessment_screen.kpi_pending')" icon-bg="bg-badge-orange">
                    <x-slot:value><span x-text="pendingCount"></span></x-slot:value>
                </x-kpi-card>

                <x-kpi-card :label="__('opes.assessment_screen.kpi_class_mean')" icon-bg="bg-badge-teal">
                    {{-- Null renders an em dash, never 0: "no mark entered yet"
                         and "the class averaged zero" are different facts. --}}
                    <x-slot:value><span x-text="classMean ?? '—'"></span></x-slot:value>
                </x-kpi-card>

                <x-kpi-card :label="__('opes.assessment_screen.kpi_out_of_range')" icon-bg="bg-heritage-red">
                    <x-slot:value><span x-text="rangeCount"></span></x-slot:value>
                </x-kpi-card>
            </div>
        </div>

        {{-- ── The keyboard legend. §17 promises a teacher can enter 62 marks
             without touching the mouse; an undiscoverable shortcut is not a
             feature. --}}
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-charcoal/70">
            <span class="font-medium">{{ __('opes.assessment_screen.keyboard_legend') }}</span>
            @foreach ($stateControls as $control)
                @if ($control['key'] !== '')
                    <span>
                        <kbd class="rounded border border-border-primary bg-sand/60 px-1 font-mono">{{ $control['key'] }}</kbd>
                        {{ $control['label'] }}
                    </span>
                @endif
            @endforeach
            <span><kbd class="rounded border border-border-primary bg-sand/60 px-1 font-mono">Del</kbd> {{ __('opes.assessment_screen.state_pending') }}</span>
            <span><kbd class="rounded border border-border-primary bg-sand/60 px-1 font-mono">&uarr;&darr;</kbd> {{ __('opes.assessment_screen.keyboard_move') }}</span>
        </p>

        @if ($grid === [])
            <x-empty-state :message="__('opes.assessment_screen.empty_grid')"/>
        @else
            {{-- Rule 4 of x-list-screen, honoured by hand: the wide thing
                 scrolls inside ITSELF; the page body never scrolls sideways. --}}
            <div class="min-w-0 overflow-x-auto rounded border border-border-primary bg-white">
                <table class="w-full min-w-[48rem] border-collapse text-sm">
                    <thead class="border-b border-border-primary bg-sand/40 text-left">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-semibold text-charcoal/70">#</th>
                            <th scope="col" class="px-3 py-2 font-semibold text-charcoal/70">{{ __('opes.assessment_screen.col_matricule') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold text-charcoal/70">{{ __('opes.assessment_screen.col_student') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold text-charcoal/70">
                                {{ __('opes.assessment_screen.col_score') }}
                                @if ($effectiveMax !== null)
                                    <span class="font-normal text-charcoal/50">/ {{ $effectiveMax }}</span>
                                @endif
                            </th>
                            <th scope="col" class="px-3 py-2 font-semibold text-charcoal/70">{{ __('opes.assessment_screen.col_state') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold text-charcoal/70">{{ __('opes.assessment_screen.col_reason') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold text-charcoal/70">{{ __('opes.assessment_screen.col_workflow') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-primary">
                        <template x-for="(row, index) in rows" :key="row.mark_id">
                            <tr :class="conflicts[row.mark_id] ? 'bg-heritage-yellow/10' : ''">
                                <td class="px-3 py-1.5 text-charcoal/50" x-text="index + 1"></td>
                                <td class="px-3 py-1.5 font-mono text-xs text-charcoal/70" x-text="row.matricule"></td>
                                <td class="px-3 py-1.5 font-medium text-charcoal" x-text="row.student"></td>

                                {{-- NO wire: binding. Alpine owns the keystroke. --}}
                                <td class="px-3 py-1.5">
                                    <input type="text" inputmode="decimal" autocomplete="off"
                                           :data-mark-index="index"
                                           :aria-label="row.student"
                                           :disabled="locked(row)"
                                           :title="locked(row) ? lockedHint : null"
                                           x-model="row.score"
                                           @input="onInput(row)"
                                           @keydown="onKey($event, index, row)"
                                           :class="outOfRange(row)
                                                ? 'w-20 rounded border border-heritage-red bg-heritage-red/5 px-2 py-1 text-sm font-semibold text-heritage-red'
                                                : 'w-20 rounded border border-border-primary px-2 py-1 text-sm text-charcoal'">
                                    <span x-show="outOfRange(row)" class="ml-1 text-xs font-medium text-heritage-red">
                                        {{ __('opes.assessment_screen.out_of_range') }}
                                    </span>
                                </td>

                                {{-- §6.4 made enterable: the state is a control
                                     of its own, never inferred from a blank
                                     number box. An absent child and a zero are
                                     8.40 points apart in one subject. --}}
                                <td class="px-3 py-1.5">
                                    <div class="flex flex-wrap items-center gap-1" role="group" :aria-label="row.student">
                                        @foreach ($stateControls as $control)
                                            <button type="button"
                                                    :disabled="locked(row)"
                                                    @click="setState(row, '{{ $control['value'] }}')"
                                                    :aria-pressed="row.state === '{{ $control['value'] }}'"
                                                    :class="row.state === '{{ $control['value'] }}'
                                                        ? 'rounded border border-primary bg-primary px-1.5 py-0.5 text-xs font-semibold text-white'
                                                        : 'rounded border border-border-primary px-1.5 py-0.5 text-xs font-medium text-charcoal/70 hover:border-primary/50'"
                                                    :title="locked(row) ? lockedHint : stateTitles['{{ $control['value'] }}']">
                                                {{ $control['marker'] !== '' ? $control['marker'] : __('opes.assessment_screen.state_short_scored') }}
                                            </button>
                                        @endforeach
                                        <span x-show="row.state === 'pending'"
                                              class="rounded-full border border-border-primary bg-sand/60 px-2 py-0.5 text-xs font-medium text-charcoal/60">
                                            {{ __('opes.assessment_screen.state_pending') }}
                                        </span>
                                    </div>
                                </td>

                                {{-- §6.4: certifying an absence or granting an
                                     exemption moves a student by whole points,
                                     so both are controlled and both demand a
                                     reason. SaveMarkBatch refuses without one;
                                     the field appears exactly when it applies. --}}
                                <td class="px-3 py-1.5">
                                    <input type="text" maxlength="255" x-show="needsReason(row)"
                                           x-model="row.comment"
                                           @input="scheduleAutosave()"
                                           :disabled="locked(row)"
                                           :title="locked(row) ? lockedHint : null"
                                           placeholder="{{ __('opes.assessment_screen.reason_placeholder') }}"
                                           :aria-label="labels.reason + ' — ' + row.student"
                                           class="w-44 rounded border border-border-primary px-2 py-1 text-sm text-charcoal">
                                </td>

                                <td class="px-3 py-1.5">
                                    <span :class="row.workflow_state === 'validated'
                                            ? 'inline-flex items-center gap-1.5 rounded-full border border-primary/40 bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary'
                                            : (row.workflow_state === 'submitted'
                                                ? 'inline-flex items-center gap-1.5 rounded-full border border-heritage-yellow/60 bg-heritage-yellow/20 px-2.5 py-0.5 text-xs font-semibold text-charcoal'
                                                : 'inline-flex items-center gap-1.5 rounded-full border border-border-primary bg-sand/60 px-2.5 py-0.5 text-xs font-semibold text-charcoal/70')">
                                        <span x-text="labels[row.workflow_state] ?? row.workflow_state"></span>
                                    </span>

                                    {{-- The inline half of T16: whose value it
                                         is and what it is, on the row itself. --}}
                                    <template x-if="conflicts[row.mark_id]">
                                        <p class="mt-1 text-xs font-medium text-charcoal"
                                           x-text="conflicts[row.mark_id].message"></p>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- ── The live footer of §17, plus the two explicit actions. ─── --}}
            <div class="flex flex-wrap items-center justify-between gap-3 rounded border border-border-primary bg-white px-3 py-2 text-sm">
                <p class="text-charcoal/70">
                    <span x-text="enteredCount"></span> {{ __('opes.assessment_screen.footer_entered') }} ·
                    <span x-text="pendingCount"></span> {{ __('opes.assessment_screen.footer_pending') }} ·
                    {{ __('opes.assessment_screen.footer_mean') }} <span class="font-semibold text-charcoal" x-text="classMean ?? '—'"></span>
                    <span x-show="rangeCount > 0" class="ml-2 font-semibold text-heritage-red">
                        <span x-text="rangeCount"></span> {{ __('opes.assessment_screen.footer_out_of_range') }}
                    </span>
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-charcoal/60" x-show="dirtyCount > 0">
                        <span x-text="dirtyCount"></span> {{ __('opes.assessment_screen.footer_unsaved') }}
                    </span>

                    <button type="button" @click="save()"
                            class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        {{ __('opes.assessment_screen.save') }}
                    </button>

                    @if ($canSubmit)
                        {{-- Explicitly separate from saving (§17): a teacher
                             saves a half-finished grid all afternoon without
                             declaring it finished. --}}
                        <button type="button" wire:click="submitForValidation"
                                wire:confirm="{{ __('opes.assessment_screen.submit_confirm') }}"
                                class="rounded border border-chrome bg-chrome px-3 py-1.5 text-sm font-medium text-white hover:bg-chrome-light">
                            {{ __('opes.assessment_screen.submit') }}
                        </button>
                    @endif

                    @if ($canReject)
                        {{-- §7.2's approve counterpart to "Return to teacher"
                             below. ValidateMarks re-checks marks.validate
                             internally; this only keeps the button from
                             rendering for an actor who could not use it. --}}
                        <button type="button" wire:click="approveMarks"
                                wire:confirm="{{ __('opes.assessment_screen.approve_confirm') }}"
                                class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('opes.assessment_screen.approve') }}
                        </button>

                        {{-- §7.4: the validator's return-to-teacher action.
                             RejectMarks always requires a reason, so this opens
                             a small inline panel rather than firing on click. --}}
                        <button type="button" wire:click="toggleRejectForm"
                                class="rounded border border-heritage-red px-3 py-1.5 text-sm font-medium text-heritage-red hover:bg-heritage-red/10">
                            {{ $showRejectForm ? __('opes.assessment_screen.reject_cancel') : __('opes.assessment_screen.reject_open') }}
                        </button>
                    @endif
                </div>
            </div>

            @if ($canReject && $showRejectForm)
                <div class="rounded border border-heritage-red/40 bg-heritage-red/5 px-3 py-3">
                    <label for="reject-reason" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.assessment_screen.reject_reason_label') }}</span>
                        <textarea id="reject-reason" wire:model="rejectReason" rows="2" maxlength="500"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                        @error('rejectReason')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" wire:click="rejectMarks"
                                wire:confirm="{{ __('opes.assessment_screen.reject_confirm') }}"
                                class="rounded bg-heritage-red px-3 py-1.5 text-sm font-medium text-white hover:bg-heritage-red/90">
                            {{ __('opes.assessment_screen.reject_submit') }}
                        </button>
                        <button type="button" wire:click="toggleRejectForm"
                                class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                            {{ __('opes.assessment_screen.reject_dismiss') }}
                        </button>
                    </div>
                </div>
            @endif
        @endif
    </div>
    @endunless
</div>
