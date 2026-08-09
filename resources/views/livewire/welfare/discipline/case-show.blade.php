{{-- One case's file — /welfare/discipline/{case}. Two-column layout like the
     attendance screens: the incident + sanctions on the left, lifecycle and
     the guardian-visibility card on the right. --}}

@php
    use App\Modules\Welfare\Domain\DisciplineCaseStatus;

    $statusTone = [
        DisciplineCaseStatus::Open->value => 'red',
        DisciplineCaseStatus::UnderInvestigation->value => 'amber',
        DisciplineCaseStatus::Resolved->value => 'ok',
        DisciplineCaseStatus::Dismissed->value => 'ok',
    ];
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('discipline.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ url('/welfare/discipline') }}" class="hover:text-primary">{{ __('discipline.breadcrumb_discipline') }}</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('discipline.case_ref', ['id' => $case->id]) }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-charcoal">
                {{ $case->is_positive ? __('discipline.positive_entry_title') : __('discipline.case_title') }}
                #{{ $case->id }}
            </h1>
            <x-status-pill :status="$statusTone[$case->status->value] ?? 'ok'"
                           :label="__('discipline.status.'.$case->status->value)"/>
            @if ($case->is_positive)
                <span class="inline-flex items-center rounded-full border border-badge-blue/40 bg-badge-blue/10 px-2.5 py-0.5 text-xs font-semibold text-badge-blue">
                    {{ __('discipline.kind_positive') }}
                </span>
            @endif
        </div>

        @if ($canManage)
            <div class="flex flex-wrap items-center gap-2">
                @if ($case->status === DisciplineCaseStatus::Open)
                    <button type="button" wire:click="markUnderInvestigation"
                            class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('discipline.start_investigation') }}
                    </button>
                @endif
                @unless ($case->is_positive)
                    <button type="button" wire:click="toggleSanctionForm"
                            class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        {{ __('discipline.apply_sanction') }}
                    </button>
                @endunless
                <button type="button" wire:click="$toggle('showResolveForm')"
                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    {{ __('discipline.close_case') }}
                </button>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="min-w-0 space-y-4 xl:col-span-2">
            {{-- ── Incident card ──────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">{{ __('discipline.incident_title') }}</h2>
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 px-4 py-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('discipline.field_student') }}</dt>
                        <dd class="mt-0.5 font-medium text-charcoal">
                            {{ $student?->first_name }} {{ $student?->last_name }}
                            <span class="block text-xs font-normal text-charcoal/60">
                                {{ $student?->matricule ?? $student?->admission_no }}
                                @if ($classGroupName) · {{ $classGroupName }} @endif
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('discipline.field_category') }}</dt>
                        <dd class="mt-0.5 text-charcoal">
                            {{ $case->category->name }}
                            <span class="block text-xs text-charcoal/60">
                                {{ __('discipline.severity') }} {{ $case->category->severity }}/5
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('discipline.field_occurred_on') }}</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $case->occurred_on->toDateString() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('discipline.reported_by') }}</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $reporterName ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('discipline.field_description') }}</dt>
                        <dd class="mt-0.5 whitespace-pre-line text-charcoal">{{ $case->description }}</dd>
                    </div>
                </dl>
            </div>

            {{-- ── Apply Sanction form ────────────────────────────────── --}}
            @if ($showSanctionForm && $canManage)
                <div class="rounded border border-sand bg-white p-4">
                    <h2 class="text-sm font-semibold text-charcoal">{{ __('discipline.apply_sanction') }}</h2>

                    @if ($suggestion !== null)
                        <p class="mt-2 rounded border border-heritage-yellow/60 bg-heritage-yellow/10 px-3 py-2 text-xs text-charcoal">
                            {{ __('discipline.ladder_suggestion', ['type' => __('discipline.sanction.'.$suggestion->value)]) }}
                            <span class="font-medium">{{ __('discipline.ladder_advisory') }}</span>
                        </p>
                    @endif

                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div>
                            <label for="sanction-type" class="block text-xs font-medium text-charcoal/70">{{ __('discipline.field_sanction_type') }}</label>
                            <select id="sanction-type" wire:model="sanctionType"
                                    class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none">
                                @foreach ($sanctionTypes as $type)
                                    <option value="{{ $type->value }}">{{ __('discipline.sanction.'.$type->value) }}</option>
                                @endforeach
                            </select>
                            @error('sanctionType') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="starts-on" class="block text-xs font-medium text-charcoal/70">{{ __('discipline.field_starts_on') }}</label>
                            <input id="starts-on" type="date" wire:model="startsOn"
                                   class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none"/>
                            @error('startsOn') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="ends-on" class="block text-xs font-medium text-charcoal/70">{{ __('discipline.field_ends_on') }}</label>
                            <input id="ends-on" type="date" wire:model="endsOn"
                                   class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none"/>
                            @error('endsOn') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-3">
                            <label for="sanction-notes" class="block text-xs font-medium text-charcoal/70">{{ __('discipline.field_notes') }}</label>
                            <input id="sanction-notes" type="text" wire:model="notes"
                                   class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none"/>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-2">
                        <button type="button" wire:click="applySanction"
                                class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('discipline.save_sanction') }}
                        </button>
                        <button type="button" wire:click="toggleSanctionForm"
                                class="rounded border border-sand px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50">
                            {{ __('opes.ui.cancel') }}
                        </button>
                    </div>
                </div>
            @endif

            {{-- ── Resolve form ───────────────────────────────────────── --}}
            @if ($showResolveForm && $canManage)
                <div class="rounded border border-sand bg-white p-4">
                    <h2 class="text-sm font-semibold text-charcoal">{{ __('discipline.close_case') }}</h2>
                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div>
                            <label for="resolve-outcome" class="block text-xs font-medium text-charcoal/70">{{ __('discipline.field_outcome') }}</label>
                            <select id="resolve-outcome" wire:model="resolveOutcome"
                                    class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none">
                                <option value="resolved">{{ __('discipline.status.resolved') }}</option>
                                <option value="dismissed">{{ __('discipline.status.dismissed') }}</option>
                            </select>
                            <p class="mt-1 text-xs text-charcoal/50">{{ __('discipline.dismiss_hint') }}</p>
                        </div>
                        <div>
                            <label for="resolution-note" class="block text-xs font-medium text-charcoal/70">{{ __('discipline.field_resolution_note') }}</label>
                            <input id="resolution-note" type="text" wire:model="resolutionNote"
                                   class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none"/>
                            @error('resolutionNote') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="button" wire:click="resolveCase"
                                class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('discipline.confirm_close') }}
                        </button>
                    </div>
                </div>
            @endif

            {{-- ── Sanctions ──────────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">{{ __('discipline.sanctions_title') }}</h2>
                </div>

                @if ($case->sanctions->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('discipline.no_sanctions')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-sand text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('discipline.field_sanction_type') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('discipline.field_starts_on') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('discipline.field_ends_on') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('discipline.field_notes') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('discipline.acknowledgement') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand bg-white">
                                @foreach ($case->sanctions as $sanction)
                                    <tr wire:key="sanction-{{ $sanction->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">
                                            {{ __('discipline.sanction.'.$sanction->type->value) }}
                                        </td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $sanction->starts_on->toDateString() }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $sanction->ends_on?->toDateString() ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $sanction->notes ?? '—' }}</td>
                                        <td class="px-4 py-2">
                                            @if ($sanction->acknowledged_at !== null)
                                                <x-status-pill status="ok"
                                                               :label="__('discipline.acknowledged_on', ['date' => $sanction->acknowledged_at->toDateString()])"/>
                                            @elseif ($canManage)
                                                <button type="button" wire:click="acknowledgeSanction({{ $sanction->id }})"
                                                        class="rounded border border-sand px-2.5 py-1 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                                    {{ __('discipline.record_acknowledgement') }}
                                                </button>
                                            @else
                                                <x-status-pill status="amber" :label="__('discipline.unacknowledged')"/>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @error('sanction') <p class="px-4 pb-3 text-xs text-heritage-red">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- ── Right rail ─────────────────────────────────────────────── --}}
        <div class="min-w-0 space-y-4">
            <div class="rounded border border-sand bg-white p-4">
                <h2 class="text-sm font-semibold text-charcoal">{{ __('discipline.lifecycle_title') }}</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('discipline.col_status') }}</dt>
                        <dd class="mt-0.5">
                            <x-status-pill :status="$statusTone[$case->status->value] ?? 'ok'"
                                           :label="__('discipline.status.'.$case->status->value)"/>
                        </dd>
                    </div>
                    @if ($case->resolved_at !== null)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('discipline.resolved_at') }}</dt>
                            <dd class="mt-0.5 text-charcoal">
                                {{ $case->resolved_at->toDateString() }}
                                @if ($resolverName) · {{ $resolverName }} @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('discipline.field_resolution_note') }}</dt>
                            <dd class="mt-0.5 text-charcoal">{{ $case->resolution_note ?? '—' }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="rounded border border-sand bg-white p-4">
                <h2 class="text-sm font-semibold text-charcoal">{{ __('discipline.field_visibility') }}</h2>
                <p class="mt-2 text-sm text-charcoal">
                    {{ __('discipline.visibility.'.$case->visibility->value) }}
                </p>
                <p class="mt-1 text-xs text-charcoal/60">
                    {{ $case->visibility->value === 'guardian'
                        ? __('discipline.visibility_guardian_note')
                        : __('discipline.visibility_internal_note') }}
                </p>
            </div>
        </div>
    </div>
</div>
