@php
    use App\Modules\Academics\Domain\AcademicYearStatus;

    /** @var \App\Modules\Academics\Models\AcademicYear|null $year */
    /** @var \Illuminate\Support\Collection<int, \App\Modules\Academics\Models\AssessmentPeriod> $terms */

    $statusLabel = fn (AcademicYearStatus $status): string => __('opes.academics.status_'.$status->value);

    // Whole weeks, from real dates - the "16 Weeks" figures in the mockup.
    $weeksBetween = fn ($startsOn, $endsOn): int => (int) ceil(($startsOn->diffInDays($endsOn) + 1) / 7);

    // Settings sub-nav (mockup left column). Only "Academic" exists in Phase 1;
    // the others follow the shell's disabled-item convention rather than
    // linking to nothing.
    $subnav = [
        ['key' => 'general', 'live' => false],
        ['key' => 'academic', 'live' => true],
        ['key' => 'admission', 'live' => false],
        ['key' => 'examination', 'live' => false],
        ['key' => 'grading', 'live' => false],
        ['key' => 'promotion', 'live' => false],
        ['key' => 'subjects', 'live' => false],
        ['key' => 'classes', 'live' => false],
        ['key' => 'term', 'live' => false],
        ['key' => 'holidays', 'live' => false],
    ];
@endphp

<div class="min-w-0 space-y-4">

    {{-- ── Breadcrumb + title. The mockup's dark-green "Save All Changes"
         split button is deliberately NOT rendered: every card on this page
         saves through its own real action (create year, set current, save
         terms), and a global save button with nothing behind it would be a
         fabricated control. ─────────────────────────────────────────────── --}}
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.academics.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li>{{ __('opes.academics.breadcrumb_settings') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.academics.breadcrumb_academic') }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3L2 8l10 5 10-5-10-5z"/>
                <path stroke-linecap="round" d="M6 10.5V15c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5M22 8v5"/>
            </svg>
        </span>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.academics.title') }}</h1>
    </div>

    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    <div class="flex min-w-0 flex-col gap-4 xl:flex-row">

        {{-- ── Settings sub-nav ─────────────────────────────────────────── --}}
        <aside class="w-full shrink-0 lg:w-full xl:w-56">
            <ul class="flex gap-1 overflow-x-auto rounded border border-sand bg-white p-2 xl:flex-col xl:overflow-visible">
                @foreach ($subnav as $item)
                    <li class="shrink-0 xl:shrink">
                        @if ($item['live'])
                            <span aria-current="page"
                                  class="flex items-center gap-2 whitespace-nowrap rounded bg-primary/10 px-3 py-2 text-sm font-semibold text-primary">
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                                {{ __('opes.academics.subnav_'.$item['key']) }}
                            </span>
                        @else
                            <span aria-disabled="true" title="{{ __('opes.nav.nav_disabled_title') }}"
                                  class="flex cursor-not-allowed items-center gap-2 whitespace-nowrap rounded px-3 py-2 text-sm text-charcoal/40">
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-charcoal/20" aria-hidden="true"></span>
                                {{ __('opes.academics.subnav_'.$item['key']) }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </aside>

        {{-- ── Main column ──────────────────────────────────────────────── --}}
        <div class="min-w-0 flex-1 space-y-4">

            {{-- Academic Session card --}}
            <section aria-label="{{ __('opes.academics.session_title') }}"
                     class="rounded border border-sand bg-white p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-charcoal">{{ __('opes.academics.session_title') }}</h2>
                        <p class="mt-0.5 text-xs text-charcoal/60">{{ __('opes.academics.session_subtitle') }}</p>
                    </div>

                    @if ($year !== null)
                        {{-- The mockup's "Active Session" toggle. Backed by the
                             real single-current invariant: green when this
                             session is current, otherwise a working button
                             that calls SetCurrentAcademicYear. --}}
                        @if ($year->is_current)
                            <x-status-pill status="ok" :label="__('opes.academics.active_session')"/>
                        @else
                            <button type="button" wire:click="setCurrent({{ $year->id }})"
                                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                                {{ __('opes.academics.set_current') }}
                            </button>
                        @endif
                    @endif
                </div>

                @if ($year === null)
                    <div class="mt-4">
                        <x-empty-state :message="__('opes.academics.no_year')">
                            <x-slot:action>
                                <p class="text-xs text-charcoal/60">{{ __('opes.academics.no_year_hint') }}</p>
                            </x-slot:action>
                        </x-empty-state>
                    </div>
                @else
                    <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.session_label') }}</dt>
                            <dd class="mt-1 rounded border border-sand bg-sand/30 px-3 py-1.5 text-sm font-medium text-charcoal">
                                {{ $year->code }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.start_date') }}</dt>
                            <dd class="mt-1 rounded border border-sand bg-sand/30 px-3 py-1.5 text-sm text-charcoal">
                                {{ $year->starts_on->translatedFormat('d M Y') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.end_date') }}</dt>
                            <dd class="mt-1 rounded border border-sand bg-sand/30 px-3 py-1.5 text-sm text-charcoal">
                                {{ $year->ends_on->translatedFormat('d M Y') }}
                            </dd>
                        </div>
                        <div>
                            {{-- Derived from real term dates (the term containing
                                 today), because Phase 1 stores no current-term
                                 flag - so this is a display, not the mockup's
                                 dropdown, which would need a setter that does
                                 not exist yet. --}}
                            <dt class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.current_term') }}</dt>
                            <dd class="mt-1 rounded border border-sand bg-sand/30 px-3 py-1.5 text-sm text-charcoal">
                                {{ $currentTerm?->name ?? __('opes.academics.no_current_term') }}
                            </dd>
                        </div>
                    </dl>

                    @if ($otherYears->isNotEmpty())
                        <div class="mt-4 border-t border-sand pt-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                                {{ __('opes.academics.other_years') }}
                            </h3>
                            <ul class="mt-2 space-y-1.5">
                                @foreach ($otherYears as $other)
                                    <li class="flex flex-wrap items-center justify-between gap-2 text-sm text-charcoal/80">
                                        <span>
                                            <span class="font-medium text-charcoal">{{ $other->code }}</span>
                                            <span class="text-charcoal/50">
                                                — {{ $other->starts_on->translatedFormat('d M Y') }}
                                                → {{ $other->ends_on->translatedFormat('d M Y') }}
                                                · {{ $statusLabel($other->status) }}
                                            </span>
                                        </span>
                                        <button type="button" wire:click="setCurrent({{ $other->id }})"
                                                class="rounded border border-sand px-2 py-1 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                            {{ __('opes.academics.set_current') }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </section>

            {{-- Create academic year card. Always offered: the contiguity rule
                 (next year starts the day after the last one ends) is enforced
                 by the Action and surfaces as an inline error on the start
                 date, not as a 500. --}}
            <section aria-label="{{ __('opes.academics.create_year_title') }}"
                     class="rounded border border-sand bg-white p-4 sm:p-5">
                <h2 class="text-base font-semibold text-charcoal">{{ __('opes.academics.create_year_title') }}</h2>

                <form wire:submit="createYear" class="mt-4 space-y-4">
                    <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                        <label for="year-code" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.code_label') }}</span>
                            <input id="year-code" type="text" wire:model="code"
                                   placeholder="{{ __('opes.academics.code_placeholder') }}"
                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('code')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="year-name" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.name_label') }}</span>
                            <input id="year-name" type="text" wire:model="name"
                                   placeholder="{{ __('opes.academics.name_placeholder') }}"
                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('name')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="year-starts" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.start_date') }}</span>
                            <input id="year-starts" type="date" wire:model="startsOn"
                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('startsOn')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="year-ends" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.end_date') }}</span>
                            <input id="year-ends" type="date" wire:model="endsOn"
                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('endsOn')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                    </div>

                    <div class="border-t border-sand pt-4">
                        <button type="submit"
                                class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('opes.academics.create_year') }}
                        </button>
                    </div>
                </form>
            </section>

            {{-- Term Structure card --}}
            <section aria-label="{{ __('opes.academics.terms_title') }}"
                     class="rounded border border-sand bg-white p-4 sm:p-5">
                <div>
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.academics.terms_title') }}</h2>
                    <p class="mt-0.5 text-xs text-charcoal/60">{{ __('opes.academics.terms_subtitle') }}</p>
                </div>

                @if ($year === null)
                    <p class="mt-4 rounded border border-dashed border-sand px-4 py-6 text-center text-sm text-charcoal/70">
                        {{ __('opes.academics.terms_need_year') }}
                    </p>
                @elseif ($terms->isNotEmpty())
                    {{-- A structure already exists; DefineTermStructure refuses
                         redefinition, so the defined terms are shown as the
                         mockup's per-term sub-cards rather than as a form. --}}
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($terms as $term)
                            <article class="rounded border border-sand bg-ivory/60 p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold text-charcoal">{{ $term->name }}</h3>
                                    @if ($currentTerm !== null && $term->is($currentTerm))
                                        <span class="inline-flex items-center rounded-full border border-primary/40 bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary">
                                            {{ __('opes.academics.current_term_chip') }}
                                        </span>
                                    @endif
                                </div>
                                <dl class="mt-2 space-y-1.5 text-sm text-charcoal/80">
                                    <div class="flex justify-between gap-2">
                                        <dt class="text-charcoal/60">{{ __('opes.academics.start_date') }}</dt>
                                        <dd>{{ $term->starts_on->translatedFormat('d M Y') }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <dt class="text-charcoal/60">{{ __('opes.academics.end_date') }}</dt>
                                        <dd>{{ $term->ends_on->translatedFormat('d M Y') }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <dt class="text-charcoal/60">{{ __('opes.academics.duration') }}</dt>
                                        <dd class="font-medium text-charcoal">
                                            {{ __('opes.academics.duration_weeks', ['weeks' => $weeksBetween($term->starts_on, $term->ends_on)]) }}
                                        </dd>
                                    </div>
                                </dl>
                            </article>
                        @endforeach
                    </div>
                @else
                    <form wire:submit="saveTerms" class="mt-4 space-y-4">
                        <label for="term-count" class="flex max-w-xs flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.number_of_terms') }}</span>
                            <select id="term-count" wire:model.live="termCount"
                                    class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="2">{{ __('opes.academics.terms_option', ['count' => 2]) }}</option>
                                <option value="3">{{ __('opes.academics.terms_option', ['count' => 3]) }}</option>
                            </select>
                        </label>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($termDates as $index => $range)
                                @php
                                    $rowStart = $range['starts_on'] !== '' ? \Illuminate\Support\Carbon::make($range['starts_on']) : null;
                                    $rowEnd = $range['ends_on'] !== '' ? \Illuminate\Support\Carbon::make($range['ends_on']) : null;
                                @endphp
                                <fieldset class="rounded border border-sand bg-ivory/60 p-3">
                                    <legend class="px-1 text-sm font-semibold text-charcoal">
                                        {{ __('opes.academics.term_n', ['number' => $index + 1]) }}
                                    </legend>
                                    <div class="space-y-2">
                                        <label for="term-{{ $index }}-starts" class="flex flex-col gap-1">
                                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.start_date') }}</span>
                                            <input id="term-{{ $index }}-starts" type="date"
                                                   wire:model.live="termDates.{{ $index }}.starts_on"
                                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                            @error('termDates.'.$index.'.starts_on')
                                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                                            @enderror
                                        </label>
                                        <label for="term-{{ $index }}-ends" class="flex flex-col gap-1">
                                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.academics.end_date') }}</span>
                                            <input id="term-{{ $index }}-ends" type="date"
                                                   wire:model.live="termDates.{{ $index }}.ends_on"
                                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                            @error('termDates.'.$index.'.ends_on')
                                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                                            @enderror
                                        </label>
                                        <p class="flex justify-between gap-2 text-sm text-charcoal/80">
                                            <span class="text-charcoal/60">{{ __('opes.academics.duration') }}</span>
                                            @if ($rowStart !== null && $rowEnd !== null && $rowEnd->greaterThanOrEqualTo($rowStart))
                                                <span class="font-medium text-charcoal">
                                                    {{ __('opes.academics.duration_weeks', ['weeks' => $weeksBetween($rowStart, $rowEnd)]) }}
                                                </span>
                                            @else
                                                <span title="{{ __('opes.ui.no_data') }}">—</span>
                                            @endif
                                        </p>
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>

                        {{-- The domain's own rejection message - gaps, overlaps,
                             out-of-year terms - lands here, readable inline. --}}
                        @error('termDates')
                            <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm text-heritage-red" role="alert">
                                {{ $message }}
                            </p>
                        @enderror

                        <div class="border-t border-sand pt-4">
                            <button type="submit"
                                    class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                                {{ __('opes.academics.save_terms') }}
                            </button>
                        </div>
                    </form>
                @endif
            </section>

            {{-- The mockup's "Academic Preferences" card (grading system,
                 passing mark, GPA toggles...) is omitted: none of those
                 fields has a consumer in Phase 1, and wiring settings nothing
                 reads would be fabricated configuration (task brief). --}}
        </div>

        {{-- ── Right rail ───────────────────────────────────────────────── --}}
        <aside class="w-full shrink-0 space-y-4 xl:w-72">

            {{-- Grading Scale: the card frame from the mockup, honestly empty -
                 no grading-scale entity exists in Phase 1, so no A/B/C rows. --}}
            <section aria-label="{{ __('opes.academics.grading_title') }}"
                     class="rounded border border-sand bg-white p-4">
                <h2 class="text-sm font-semibold text-charcoal">{{ __('opes.academics.grading_title') }}</h2>
                <p class="mt-3 rounded border border-dashed border-sand px-3 py-6 text-center text-xs text-charcoal/60">
                    {{ __('opes.academics.grading_empty') }}
                </p>
            </section>

            {{-- Subjects Overview: real Subject::count() queries only. The
                 mockup's core/elective split has no backing field yet. --}}
            <section aria-label="{{ __('opes.academics.subjects_overview') }}"
                     class="rounded border border-sand bg-white p-4">
                <h2 class="text-sm font-semibold text-charcoal">{{ __('opes.academics.subjects_overview') }}</h2>
                <dl class="mt-3 space-y-2.5 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="flex items-center gap-2 text-charcoal/70">
                            <span class="flex h-7 w-7 items-center justify-center rounded bg-badge-blue/10 text-badge-blue" aria-hidden="true">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5A2.5 2.5 0 006.5 22H20V2H6.5A2.5 2.5 0 004 4.5v15z"/>
                                </svg>
                            </span>
                            {{ __('opes.academics.total_subjects') }}
                        </dt>
                        <dd class="font-semibold text-charcoal">{{ $totalSubjects }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="flex items-center gap-2 text-charcoal/70">
                            <span class="flex h-7 w-7 items-center justify-center rounded bg-primary/10 text-primary" aria-hidden="true">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 6L9 17l-5-5"/>
                                </svg>
                            </span>
                            {{ __('opes.academics.active_subjects') }}
                        </dt>
                        <dd class="font-semibold text-charcoal">{{ $activeSubjects }}</dd>
                    </div>
                </dl>
                <a href="{{ route('subjects.index') }}"
                   class="mt-4 flex items-center justify-center gap-2 rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2V3zM22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7V3z"/>
                    </svg>
                    {{ __('opes.academics.manage_subjects') }}
                </a>
            </section>

            {{-- The mockup's "Next Academic Events" card is omitted entirely:
                 no events entity exists in Phase 1, and inventing "Term 1
                 Mid-Term Exams - 26 Oct" rows would be fabricated data. --}}
        </aside>
    </div>
</div>
