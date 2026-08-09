{{-- Student Promotion Wizard — docs/specs/07-students.md §10.
     evaluate → review/override → apply.

     No mockup exists for this screen in `frontend images/`; the chrome
     mirrors the admission wizard's numbered rail and the students screens'
     card/table vocabulary exactly (phase plan UI-fidelity rule).

     Everything shown is what the run row and its decisions actually hold —
     `computed_outcome` stays printed beside an override so a manual change
     is never invisible (§10.5), and criteria verdicts render per row so the
     list explains itself. --}}

@php
    use App\Modules\Students\Domain\PromotionOutcome;
    use App\Modules\Students\Domain\PromotionRunStatus;

    $stepLabels = [1 => 'Evaluate', 2 => 'Review & Override', 3 => 'Applied'];
    $applied = $run !== null && $run->status === PromotionRunStatus::Applied;
@endphp

<div class="min-w-0 space-y-5">
    <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li>Students</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">Promotion</li>
        </ol>
    </nav>

    <h1 class="text-xl font-semibold text-charcoal">Student Promotion</h1>

    @if ($statusMessage !== '')
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ $statusMessage }}
        </p>
    @endif

    @foreach (['class_group_id', 'criteria_set_id', 'target_academic_year_id', 'on_indeterminate', 'promotion_run_id', 'inputs_hash', 'enrollment_id', 'actor', 'outcome', 'override_reason', 'override_outcome', 'target_class_level_id', 'target_class_group_id', 'academic_year_id'] as $errKey)
        @error($errKey)
            <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm text-heritage-red" role="alert">
                {{ $message }}
            </p>
        @enderror
    @endforeach

    {{-- Numbered progress rail, admission-wizard chrome. --}}
    <ol aria-label="Student Promotion"
        class="flex w-full items-start justify-between gap-1 overflow-x-auto rounded-lg border border-sand bg-white px-4 py-5 shadow-sm">
        @foreach ($stepLabels as $number => $label)
            <li class="flex min-w-24 flex-1 flex-col items-center gap-2 text-center"
                @if ($number === $step) aria-current="step" @endif>
                <div class="flex w-full items-center">
                    <span class="h-px flex-1 {{ $loop->first ? 'bg-transparent' : ($number <= $step ? 'bg-primary' : 'bg-sand') }}"></span>
                    <span aria-hidden="true"
                          class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border text-sm font-semibold
                                 {{ $number === $step
                                        ? 'border-chrome bg-chrome text-white'
                                        : ($number < $step
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-sand bg-white text-charcoal/50') }}">
                        {{ $number }}
                    </span>
                    <span class="h-px flex-1 {{ $loop->last ? 'bg-transparent' : ($number < $step ? 'bg-primary' : 'bg-sand') }}"></span>
                </div>
                <span class="text-xs {{ $number === $step ? 'font-semibold text-primary' : 'text-charcoal/60' }}">{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    {{-- ===================== Step 1: Evaluate ===================== --}}
    <section class="rounded-lg border border-sand bg-white p-5 shadow-sm" aria-label="Evaluate">
        <h2 class="text-base font-semibold text-charcoal">Step 1: Evaluate a class group</h2>
        <p class="mt-1 text-xs text-charcoal/60">
            The evaluation is persisted with a hash of its inputs; apply re-validates that hash days later
            and refuses if marks, attendance or discipline changed in between.
        </p>

        <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
            <label for="promo-year" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Academic year to close <span class="text-heritage-red">*</span></span>
                <select id="promo-year" wire:model.live="academic_year_id" @disabled($applied)
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">Select year…</option>
                    @foreach ($years as $year)
                        <option value="{{ $year->id }}">{{ $year->code }}{{ $year->is_current ? ' (current)' : '' }}</option>
                    @endforeach
                </select>
            </label>

            <label for="promo-group" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Class group <span class="text-heritage-red">*</span></span>
                <select id="promo-group" wire:model="class_group_id" @disabled($applied)
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">Select class group…</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}">{{ $group->level_name }} — {{ $group->name }}</option>
                    @endforeach
                </select>
            </label>

            <label for="promo-criteria" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Criteria set <span class="text-heritage-red">*</span></span>
                <select id="promo-criteria" wire:model="criteria_set_id" @disabled($applied)
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">Select criteria set…</option>
                    @foreach ($criteriaSets as $set)
                        <option value="{{ $set->id }}">{{ $set->name }} (v{{ $set->version }})</option>
                    @endforeach
                </select>
            </label>

            <label for="promo-target-year" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Target year <span class="text-heritage-red">*</span></span>
                <select id="promo-target-year" wire:model="target_academic_year_id" @disabled($applied)
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">Select target year…</option>
                    @foreach ($years as $year)
                        <option value="{{ $year->id }}">{{ $year->code }}</option>
                    @endforeach
                </select>
            </label>

            <label for="promo-indeterminate" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">When a criterion cannot be computed</span>
                <select id="promo-indeterminate" wire:model="on_indeterminate" @disabled($applied)
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="block">Block the apply (default)</option>
                    <option value="manual_review">Route the student to manual review</option>
                </select>
            </label>
        </div>

        <div class="mt-5 flex items-center justify-end gap-3 border-t border-sand pt-4">
            @if ($run !== null && ! $applied)
                <button type="button" wire:click="reevaluate"
                        class="rounded border border-sand px-4 py-1.5 text-sm font-medium text-charcoal hover:bg-sand/40">
                    Re-evaluate with fresh inputs
                </button>
            @endif

            <button type="button" wire:click="evaluate" @disabled($applied)
                    class="rounded bg-primary px-5 py-1.5 text-sm font-semibold text-white hover:bg-primary/90 disabled:opacity-40">
                Evaluate
            </button>
        </div>
    </section>

    {{-- ================ Step 2: Review & Override ================= --}}
    @if ($run !== null)
        <section class="rounded-lg border border-sand bg-white p-5 shadow-sm" aria-label="Review and override">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-charcoal">Step 2: Review the proposed decisions</h2>
                <span class="rounded-full border px-3 py-0.5 text-xs font-semibold
                             {{ $applied ? 'border-primary/40 bg-primary/10 text-primary' : 'border-heritage-yellow/60 bg-heritage-yellow/10 text-charcoal' }}">
                    Run {{ $run->id }} · {{ $run->status->label() }}
                </span>
            </div>

            {{-- KPI cards, students-screen chrome. --}}
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div class="rounded-lg border border-sand bg-white p-3 text-center shadow-sm">
                    <p class="text-lg font-semibold text-charcoal">{{ count($decisions) }}</p>
                    <p class="text-xs text-charcoal/60">Students</p>
                </div>
                <div class="rounded-lg border border-sand bg-white p-3 text-center shadow-sm">
                    <p class="text-lg font-semibold text-primary">{{ $counts['promote'] }}</p>
                    <p class="text-xs text-charcoal/60">Promote</p>
                </div>
                <div class="rounded-lg border border-sand bg-white p-3 text-center shadow-sm">
                    <p class="text-lg font-semibold text-heritage-red">{{ $counts['repeat'] }}</p>
                    <p class="text-xs text-charcoal/60">Repeat</p>
                </div>
                <div class="rounded-lg border border-sand bg-white p-3 text-center shadow-sm">
                    <p class="text-lg font-semibold text-chrome">{{ $counts['graduate'] }}</p>
                    <p class="text-xs text-charcoal/60">Graduate</p>
                </div>
                <div class="rounded-lg border border-sand bg-white p-3 text-center shadow-sm">
                    <p class="text-lg font-semibold {{ $counts['undecided'] > 0 ? 'text-heritage-red' : 'text-charcoal' }}">{{ $counts['undecided'] }}</p>
                    <p class="text-xs text-charcoal/60">Undecided</p>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[52rem] border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-sand text-xs uppercase tracking-wide text-charcoal/60">
                            <th class="px-3 py-2">Student</th>
                            <th class="px-3 py-2">Annual avg.</th>
                            <th class="px-3 py-2">Attendance</th>
                            <th class="px-3 py-2">Criteria</th>
                            <th class="px-3 py-2">Outcome</th>
                            <th class="px-3 py-2">Computed</th>
                            <th class="px-3 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($decisions as $decision)
                            @php
                                $outcome = $decision->outcome;
                                $results = $decision->criteria_results['criteria'] ?? [];
                                $badge = match (true) {
                                    $outcome === null => 'border-sand bg-sand/40 text-charcoal/60',
                                    $outcome === PromotionOutcome::Promote,
                                    $outcome === PromotionOutcome::ConditionalPromote,
                                    $outcome === PromotionOutcome::Graduate => 'border-primary/40 bg-primary/10 text-primary',
                                    $outcome === PromotionOutcome::Repeat,
                                    $outcome === PromotionOutcome::Exclude => 'border-heritage-red/40 bg-heritage-red/10 text-heritage-red',
                                    default => 'border-heritage-yellow/60 bg-heritage-yellow/10 text-charcoal',
                                };
                            @endphp
                            <tr class="border-b border-sand/60 align-top hover:bg-sand/20">
                                <td class="px-3 py-2">
                                    <p class="font-medium text-charcoal">{{ $decision->last_name }} {{ $decision->first_name }}</p>
                                    <p class="text-xs text-charcoal/60">{{ $decision->matricule }}</p>
                                </td>
                                <td class="px-3 py-2 tabular-nums">{{ $decision->annual_average ?? '—' }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $decision->attendance_rate !== null ? $decision->attendance_rate.'%' : '—' }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($results as $result)
                                            <span title="{{ $result['type'] }} {{ $result['comparator'] }} {{ $result['threshold'] }} (value: {{ $result['value'] ?? '—' }})"
                                                  class="rounded-full border px-2 py-0.5 text-[11px]
                                                         {{ ($result['verdict'] ?? '') === 'pass'
                                                                ? 'border-primary/40 bg-primary/10 text-primary'
                                                                : (($result['verdict'] ?? '') === 'fail'
                                                                    ? 'border-heritage-red/40 bg-heritage-red/10 text-heritage-red'
                                                                    : 'border-heritage-yellow/60 bg-heritage-yellow/10 text-charcoal') }}">
                                                {{ str_replace('_', ' ', (string) ($result['type'] ?? '')) }}: {{ $result['verdict'] ?? '—' }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $badge }}">
                                        {{ $outcome?->label() ?? '—' }}
                                    </span>
                                    @if ($decision->overridden)
                                        <p class="mt-1 text-[11px] text-charcoal/60" title="{{ $decision->override_reason }}">Overridden</p>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-charcoal/60">
                                    {{ str_replace('_', ' ', (string) ($decision->computed_outcome ?? '—')) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    @if (! $applied)
                                        <button type="button" wire:click="openOverride({{ $decision->id }})"
                                                class="rounded border border-sand px-2.5 py-1 text-xs font-medium text-charcoal hover:bg-sand/40">
                                            Override
                                        </button>
                                    @endif
                                </td>
                            </tr>

                            @if ($overridingDecisionId === (int) $decision->id && ! $applied)
                                <tr class="border-b border-sand/60 bg-sand/20">
                                    <td colspan="7" class="px-3 py-3">
                                        <div class="flex flex-wrap items-end gap-3">
                                            <label class="flex flex-col gap-1">
                                                <span class="text-xs font-medium text-charcoal/70">Conseil outcome <span class="text-heritage-red">*</span></span>
                                                <select wire:model="override_outcome"
                                                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                                    <option value="">Choose…</option>
                                                    @foreach ($overrideOutcomes as $option)
                                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="flex min-w-64 flex-1 flex-col gap-1">
                                                <span class="text-xs font-medium text-charcoal/70">Reason (printed on the promotion list) <span class="text-heritage-red">*</span></span>
                                                <input type="text" wire:model="override_reason"
                                                       class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                            </label>
                                            <div class="flex gap-2">
                                                <button type="button" wire:click="saveOverride"
                                                        class="rounded bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                                                    Save override
                                                </button>
                                                <button type="button" wire:click="cancelOverride"
                                                        class="rounded border border-sand px-3 py-1.5 text-sm text-charcoal hover:bg-sand/40">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-6 text-center text-sm text-charcoal/60">
                                    No decisions yet — run the evaluation above.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- ======================= Step 3: Apply ======================= --}}
        <section class="rounded-lg border border-sand bg-white p-5 shadow-sm" aria-label="Apply">
            <h2 class="text-base font-semibold text-charcoal">Step 3: Apply</h2>

            @if ($applied)
                <p class="mt-2 rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
                    Applied {{ $run->applied_at?->format('Y-m-d H:i') }} — the outgoing enrollments are completed and
                    next-year enrollments exist. Cancelling an applied run is not supported; corrections are
                    per-student manual enrollment changes with a reason.
                </p>
            @else
                <p class="mt-1 text-xs text-charcoal/60">
                    One transaction: re-validates the inputs hash, closes each student's year
                    (segment + enrollment), creates the next-year enrollments (deferred class groups stay
                    pending for the rollover wizard), and emits the promotion events after commit.
                </p>

                <div class="mt-4 flex items-center justify-end gap-3 border-t border-sand pt-4">
                    @if (! $canApply)
                        <p class="text-xs text-charcoal/60">Applying requires the promotion.apply permission.</p>
                    @endif
                    <button type="button" wire:click="apply" @disabled(! $canApply || $counts['undecided'] > 0)
                            class="rounded bg-chrome px-5 py-1.5 text-sm font-semibold text-white hover:bg-chrome/90 disabled:opacity-40">
                        Apply promotion
                    </button>
                </div>

                @if ($counts['undecided'] > 0)
                    <p class="mt-2 text-right text-xs text-heritage-red">
                        {{ $counts['undecided'] }} student(s) are undecided — override each on the record, or fix the
                        missing inputs and re-evaluate, before applying.
                    </p>
                @endif
            @endif
        </section>
    @endif
</div>
