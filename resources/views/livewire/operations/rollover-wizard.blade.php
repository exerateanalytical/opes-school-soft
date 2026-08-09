{{-- Year Rollover Wizard, docs/specs/08-operations.md §6.2 (Phase 7 F5).

     No dedicated mockup exists for this screen; per the F5 brief it mirrors
     the Admission Wizard's chrome exactly (frontend images/flow wizards.png
     panel 1): breadcrumb, numbered progress rail with the current step in
     chrome-green, the step's form in the main column with Apply bottom-right,
     and a summary aside. The preview diff (§6.3 "previewable") renders above
     every Apply; nothing on this screen is decoration - every figure comes
     from PreviewStep or the run row. --}}

@php
    use App\Modules\Operations\Domain\RolloverRunStatus;
    use App\Modules\Operations\Domain\RolloverStep;

    $isRunning = $run !== null && $run->status() === RolloverRunStatus::Running;
    $isCompleted = $run !== null && $run->status() === RolloverRunStatus::Completed;
@endphp

<div class="min-w-0 space-y-5">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('rollover.wizard.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li>{{ __('rollover.wizard.breadcrumb_operations') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('rollover.wizard.breadcrumb_rollover') }}</li>
        </ol>
    </nav>

    <div>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('rollover.wizard.title') }}</h1>
        <p class="text-xs text-charcoal/60">{{ __('rollover.wizard.subtitle') }}</p>
    </div>

    @if ($statusMessage !== '')
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ $statusMessage }}
        </p>
    @endif

    @if ($errorMessage !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
            {{ $errorMessage }}
        </p>
    @endif

    {{-- A resumable run from an earlier session (§6.3): offered, never
         silently adopted. --}}
    @if ($run === null && $resumable !== null)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded border border-heritage-yellow/60 bg-heritage-yellow/10 px-4 py-3">
            <p class="text-sm text-charcoal" role="status">
                {{ __('rollover.wizard.resume_notice', ['id' => $resumable->getKey(), 'step' => $resumable->current_step]) }}
            </p>
            <button type="button" wire:click="resume({{ $resumable->getKey() }})"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ __('rollover.wizard.resume') }}
            </button>
        </div>
    @endif

    {{-- The numbered progress rail: eleven stations, 0-10. aria-current, not
         colour alone (09-ui 10). --}}
    <ol aria-label="{{ __('rollover.wizard.title') }}"
        class="flex w-full items-start justify-between gap-1 overflow-x-auto rounded-lg border border-sand bg-white px-4 py-5 shadow-sm">
        @foreach ($steps as $stepOption)
            @php $stepDone = $currentStep !== null && ($stepOption->value < $currentStep->value || $isCompleted); @endphp
            <li class="flex min-w-16 flex-1 flex-col items-center gap-2 text-center"
                @if (! $isCompleted && $currentStep !== null && $stepOption === $currentStep) aria-current="step" @endif>
                <div class="flex w-full items-center">
                    <span class="h-px flex-1 {{ $loop->first ? 'bg-transparent' : ($stepDone || ($currentStep !== null && $stepOption->value <= $currentStep->value) ? 'bg-primary' : 'bg-sand') }}"></span>
                    <span aria-hidden="true"
                          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-xs font-semibold
                                 {{ ! $isCompleted && $stepOption === $currentStep
                                        ? 'border-chrome bg-chrome text-white'
                                        : ($stepDone
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-sand bg-white text-charcoal/50') }}">
                        {{ $stepOption->value }}
                    </span>
                    <span class="h-px flex-1 {{ $loop->last ? 'bg-transparent' : ($stepDone ? 'bg-primary' : 'bg-sand') }}"></span>
                </div>
                <span class="text-[11px] leading-tight {{ ! $isCompleted && $stepOption === $currentStep ? 'font-semibold text-primary' : 'text-charcoal/60' }}">
                    {{ $stepOption->label(app()->getLocale()) }}
                </span>
            </li>
        @endforeach
    </ol>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <section class="rounded-lg border border-sand bg-white p-5 shadow-sm"
                 aria-label="{{ $currentStep?->label(app()->getLocale()) ?? RolloverStep::Preflight->label(app()->getLocale()) }}">

            {{-- ================= Before a run exists: step 0 ================= --}}
            @if ($run === null)
                <h2 class="text-base font-semibold text-charcoal">
                    {{ __('rollover.wizard.step_counter', ['current' => 0, 'total' => 10]) }}:
                    {{ RolloverStep::Preflight->label(app()->getLocale()) }}
                </h2>

                <ul class="mt-4 space-y-1.5 text-sm text-charcoal/80">
                    @foreach (['preflight_backup', 'preflight_periods', 'preflight_drafts', 'preflight_cashdesk'] as $check)
                        <li class="flex items-start gap-2">
                            <span aria-hidden="true" class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                            {{ __('rollover.wizard.checklist.'.$check) }}
                        </li>
                    @endforeach
                </ul>

                <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="ro-from-year" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('rollover.wizard.from_year') }} <span class="text-heritage-red">*</span></span>
                        <select id="ro-from-year" wire:model="fromYearId"
                                class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('rollover.wizard.choose') }}</option>
                            @foreach ($years as $id => $code)
                                <option value="{{ $id }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        @if ($years === [])
                            <span class="text-xs text-charcoal/50">{{ __('rollover.wizard.no_years') }}</span>
                        @endif
                    </label>

                    <label for="ro-backup" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('rollover.wizard.backup') }} <span class="text-heritage-red">*</span></span>
                        <select id="ro-backup" wire:model="backupId"
                                class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('rollover.wizard.choose') }}</option>
                            @foreach ($backups as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs text-charcoal/50">
                            {{ $backups === [] ? __('rollover.wizard.no_backups') : __('rollover.wizard.backup_hint') }}
                        </span>
                    </label>
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-sand pt-5">
                    @if ($canTakeBackup)
                        <button type="button" wire:click="takeBackup"
                                class="rounded border border-sand px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('rollover.wizard.take_backup') }}
                        </button>
                    @endif

                    <button type="button" wire:click="start"
                            class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        {{ __('rollover.wizard.start') }}
                    </button>
                </div>
            @elseif ($isCompleted)
                {{-- ================= Completed ================= --}}
                <h2 class="text-base font-semibold text-charcoal">{{ __('rollover.wizard.completed_title') }}</h2>
                <p class="mt-3 rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm text-charcoal" role="status">
                    {{ __('rollover.wizard.completed_note') }}
                </p>

                <div class="mt-6 flex justify-end border-t border-sand pt-5">
                    <button type="button" wire:click="undo" wire:confirm="{{ __('rollover.wizard.undo_confirm') }}"
                            class="rounded border border-heritage-red/50 px-4 py-1.5 text-sm font-medium text-heritage-red hover:bg-heritage-red/5">
                        {{ __('rollover.wizard.undo') }}
                    </button>
                </div>
            @else
                <h2 class="text-base font-semibold text-charcoal">
                    {{ __('rollover.wizard.step_counter', ['current' => $currentStep->value, 'total' => 10]) }}:
                    {{ $currentStep->label(app()->getLocale()) }}
                </h2>

                {{-- ── Per-step inputs ─────────────────────────────────── --}}
                @if ($currentStep === RolloverStep::CreateNewYear)
                    <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                        <label for="ro-code" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('rollover.wizard.new_year_code') }} <span class="text-heritage-red">*</span></span>
                            <input id="ro-code" type="text" wire:model="newYearCode"
                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        </label>
                        <label for="ro-name" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('rollover.wizard.new_year_name') }} <span class="text-heritage-red">*</span></span>
                            <input id="ro-name" type="text" wire:model="newYearName"
                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        </label>
                        <label for="ro-ends" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('rollover.wizard.new_year_ends_on') }}</span>
                            <input id="ro-ends" type="date" wire:model="newYearEndsOn"
                                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            <span class="text-xs text-charcoal/50">{{ __('rollover.wizard.new_year_ends_on_hint') }}</span>
                        </label>
                    </div>
                @endif

                @if ($currentStep === RolloverStep::CopyFeeStructures)
                    <label for="ro-uplift" class="mt-4 flex max-w-xs flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('rollover.wizard.uplift_percent') }}</span>
                        <input id="ro-uplift" type="number" step="0.01" min="0" wire:model="upliftPercent"
                               class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        <span class="text-xs text-charcoal/50">{{ __('rollover.wizard.uplift_hint') }}</span>
                    </label>
                @endif

                @if ($currentStep === RolloverStep::PromoteStudents)
                    <h3 class="mt-5 border-b border-sand pb-2 text-sm font-semibold text-primary">
                        {{ __('rollover.wizard.decisions_title') }}
                    </h3>

                    @if ($pendingDecisions === [])
                        <p class="mt-3 text-sm text-charcoal/70">{{ __('rollover.wizard.decisions_done') }}</p>
                    @else
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full min-w-[36rem] text-left text-sm">
                                <thead>
                                    <tr class="border-b border-sand text-xs uppercase tracking-wide text-charcoal/60">
                                        <th class="py-2 pr-3 font-medium">{{ __('rollover.wizard.student') }}</th>
                                        <th class="py-2 pr-3 font-medium">{{ __('rollover.wizard.class_group') }}</th>
                                        <th class="py-2 pr-3 font-medium">{{ __('rollover.wizard.decision') }}</th>
                                        <th class="py-2 pr-3 font-medium">{{ __('rollover.wizard.target_group') }}</th>
                                        <th class="py-2 font-medium"><span class="sr-only">{{ __('rollover.wizard.save_decision') }}</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($pendingDecisions as $row)
                                        <tr class="border-b border-sand/60">
                                            <td class="py-2 pr-3 font-medium text-charcoal">{{ $row->first_name }} {{ $row->last_name }}</td>
                                            <td class="py-2 pr-3 text-charcoal/70">{{ $row->group_name }}</td>
                                            <td class="py-2 pr-3">
                                                <select wire:model="decisions.{{ $row->id }}.decision" aria-label="{{ __('rollover.wizard.decision') }}"
                                                        class="rounded border border-sand bg-white px-2 py-1 text-sm text-charcoal focus:border-primary/50">
                                                    <option value="">{{ __('rollover.wizard.choose') }}</option>
                                                    <option value="promoted">{{ __('rollover.wizard.decision_promoted') }}</option>
                                                    <option value="repeat">{{ __('rollover.wizard.decision_repeat') }}</option>
                                                    <option value="graduated">{{ __('rollover.wizard.decision_graduated') }}</option>
                                                    <option value="withdrawn">{{ __('rollover.wizard.decision_withdrawn') }}</option>
                                                </select>
                                            </td>
                                            <td class="py-2 pr-3">
                                                <select wire:model="decisions.{{ $row->id }}.target" aria-label="{{ __('rollover.wizard.target_group') }}"
                                                        class="rounded border border-sand bg-white px-2 py-1 text-sm text-charcoal focus:border-primary/50">
                                                    <option value="">{{ __('rollover.wizard.choose') }}</option>
                                                    @foreach ($targetGroups as $name)
                                                        <option value="group:{{ $name }}">{{ $name }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="py-2 text-right">
                                                <button type="button" wire:click="saveDecision({{ $row->id }})"
                                                        class="rounded border border-primary/40 px-3 py-1 text-xs font-medium text-primary hover:bg-primary/5">
                                                    {{ __('rollover.wizard.save_decision') }}
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif

                @if ($currentStep === RolloverStep::CarryBalances)
                    <h3 class="mt-5 border-b border-sand pb-2 text-sm font-semibold text-primary">
                        {{ __('rollover.wizard.debtors_title') }}
                    </h3>
                    <p class="mt-2 text-xs text-charcoal/60">{{ __('rollover.wizard.debtors_hint') }}</p>

                    @if ($debtors === [])
                        <p class="mt-3 text-sm text-charcoal/70">{{ __('rollover.wizard.debtors_none') }}</p>
                    @else
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full min-w-[28rem] text-left text-sm">
                                <thead>
                                    <tr class="border-b border-sand text-xs uppercase tracking-wide text-charcoal/60">
                                        <th class="py-2 pr-3 font-medium">{{ __('rollover.wizard.student') }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">{{ __('rollover.wizard.debtor_outstanding') }}</th>
                                        <th class="py-2 font-medium">{{ __('rollover.wizard.decision') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($debtors as $debtor)
                                        <tr class="border-b border-sand/60">
                                            <td class="py-2 pr-3 font-medium text-charcoal">{{ $debtor['name'] }}</td>
                                            <td class="py-2 pr-3 text-right tabular-nums text-charcoal">
                                                {{ number_format($debtor['outstanding'], 0, ',', ' ') }} FCFA
                                            </td>
                                            <td class="py-2">
                                                <select wire:model="debtorChoices.{{ $debtor['student_id'] }}" aria-label="{{ __('rollover.wizard.decision') }}"
                                                        class="rounded border border-sand bg-white px-2 py-1 text-sm text-charcoal focus:border-primary/50">
                                                    <option value="">{{ __('rollover.wizard.choose') }}</option>
                                                    <option value="debt_carry">{{ __('rollover.wizard.debtor_choice_carry') }}</option>
                                                    <option value="block">{{ __('rollover.wizard.debtor_choice_block') }}</option>
                                                </select>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif

                @if ($currentStep === RolloverStep::ReassignTeachers)
                    <p class="mt-4 text-xs text-charcoal/60">{{ __('rollover.wizard.teachers_hint') }}</p>

                    @if ($allocations === [])
                        <p class="mt-3 text-sm text-charcoal/70">{{ __('rollover.wizard.teachers_none') }}</p>
                    @else
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full min-w-[36rem] text-left text-sm">
                                <thead>
                                    <tr class="border-b border-sand text-xs uppercase tracking-wide text-charcoal/60">
                                        <th class="py-2 pr-3 font-medium">{{ __('rollover.wizard.allocation') }}</th>
                                        <th class="py-2 pr-3 font-medium">{{ __('rollover.wizard.inherited') }}</th>
                                        <th class="py-2 font-medium">{{ __('rollover.wizard.override_ids') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($allocations as $allocation)
                                        <tr class="border-b border-sand/60 align-top">
                                            <td class="py-2 pr-3 font-medium text-charcoal">{{ $allocation['label'] }}</td>
                                            <td class="py-2 pr-3">
                                                @forelse ($allocation['inherited'] as $teacher)
                                                    <span class="mb-1 mr-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium
                                                                 {{ $teacher['active']
                                                                        ? 'bg-primary/10 text-primary'
                                                                        : 'bg-heritage-red/10 text-heritage-red line-through' }}">
                                                        {{ $teacher['name'] }}
                                                    </span>
                                                @empty
                                                    <span class="text-xs text-charcoal/50">{{ __('opes.ui.no_data') }}</span>
                                                @endforelse
                                            </td>
                                            <td class="py-2">
                                                <input type="text" wire:model="teacherOverrides.{{ $allocation['id'] }}"
                                                       aria-label="{{ __('rollover.wizard.override_ids') }}" placeholder="12, 34"
                                                       class="w-32 rounded border border-sand bg-white px-2 py-1 text-sm text-charcoal focus:border-primary/50"/>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif

                {{-- ── The preview diff (§6.3): counts + row list under 200,
                     rendered before every Apply. ────────────────────────── --}}
                @if ($preview !== null)
                    <h3 class="mt-6 border-b border-sand pb-2 text-sm font-semibold text-primary">
                        {{ __('rollover.wizard.preview_title') }}
                    </h3>

                    <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3" aria-label="{{ __('rollover.wizard.preview_counts') }}">
                        @foreach ($preview['counts'] as $entity => $count)
                            <div class="rounded border border-sand bg-sand/20 p-3">
                                <dt class="break-words text-xs text-charcoal/60">{{ str_replace('_', ' ', $entity) }}</dt>
                                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-charcoal">{{ $count }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($preview['rows'] !== [])
                        <details class="mt-3 rounded border border-sand bg-white">
                            <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-charcoal/80">
                                {{ __('rollover.wizard.preview_rows') }} ({{ count($preview['rows']) }})
                            </summary>
                            <div class="overflow-x-auto border-t border-sand px-3 py-2">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="border-b border-sand text-charcoal/60">
                                            @foreach (array_keys($preview['rows'][0]) as $column)
                                                <th class="py-1 pr-3 font-medium">{{ str_replace('_', ' ', (string) $column) }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($preview['rows'] as $row)
                                            <tr class="border-b border-sand/50">
                                                @foreach ($row as $value)
                                                    <td class="py-1 pr-3 text-charcoal">
                                                        {{ is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? $value : '—') }}
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @elseif (array_sum($preview['counts']) >= 200)
                        <p class="mt-2 text-xs text-charcoal/50">{{ __('rollover.wizard.preview_over_cap') }}</p>
                    @endif
                @endif

                <div class="mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-sand pt-5">
                    <button type="button" wire:click="undo" wire:confirm="{{ __('rollover.wizard.undo_confirm') }}"
                            class="rounded border border-heritage-red/50 px-4 py-1.5 text-sm font-medium text-heritage-red hover:bg-heritage-red/5">
                        {{ __('rollover.wizard.undo') }}
                    </button>

                    @if ($isRunning)
                        <button type="button" wire:click="apply"
                                class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('rollover.wizard.apply') }}
                        </button>
                    @endif
                </div>
            @endif
        </section>

        {{-- ── Run summary aside, mirroring the Admission Summary panel ── --}}
        <aside class="h-fit rounded-lg border border-sand bg-ivory p-5 shadow-sm"
               aria-label="{{ __('rollover.wizard.run_label', ['id' => $run?->getKey() ?? '—']) }}">
            <h2 class="border-b border-sand pb-2 text-sm font-semibold text-chrome">
                {{ $run === null ? __('rollover.wizard.title') : __('rollover.wizard.run_label', ['id' => $run->getKey()]) }}
            </h2>

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between gap-3 border-b border-sand pb-1">
                    <dt class="text-charcoal/60">{{ __('rollover.wizard.status') }}</dt>
                    <dd class="font-medium text-charcoal">
                        {{ $run === null ? __('opes.ui.no_data') : __('rollover.run_status.'.$run->status) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-sand pb-1">
                    <dt class="text-charcoal/60">{{ __('rollover.wizard.from_year') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $fromYear?->code ?? __('opes.ui.no_data') }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-sand pb-1">
                    <dt class="text-charcoal/60">{{ __('rollover.wizard.new_year_name') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $toYear?->code ?? __('opes.ui.no_data') }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-sand pb-1">
                    <dt class="text-charcoal/60">{{ __('rollover.wizard.backup') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $run?->backup_id !== null ? '#'.$run?->backup_id : __('opes.ui.no_data') }}</dd>
                </div>
            </dl>

            <p class="mt-4 rounded border border-primary/30 bg-primary/5 px-3 py-2 text-xs text-charcoal/80">
                {{ __('rollover.wizard.completed_note') }}
            </p>
        </aside>
    </div>
</div>
