@php
    use App\Modules\Operations\Domain\Licensing\LicenceState;
    use App\Modules\Operations\Models\Licence;

    /** @var \App\Modules\Operations\Licensing\LicenceEvaluation $evaluation */
    $state = $evaluation->state;
    $payload = $evaluation->payload();

    // 09-ui section 10: colour is never the only signal - the state label
    // carries the meaning; the pill tone only reinforces it.
    $pillStatus = match ($state) {
        LicenceState::Valid, LicenceState::Trial => 'ok',
        LicenceState::Expiring, LicenceState::Grace => 'amber',
        LicenceState::Enforced, LicenceState::Revoked => 'red',
    };
@endphp

<div class="min-w-0 space-y-4">

    {{-- ── Breadcrumb + title (settings-page chrome, as academic-settings) ── --}}
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('licence.panel.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li>{{ __('licence.panel.breadcrumb_settings') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('licence.panel.breadcrumb_licence') }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 8a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3z"/>
            </svg>
        </span>
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ __('licence.panel.title') }}</h1>
            <p class="text-xs text-charcoal/60">{{ __('licence.panel.subtitle') }}</p>
        </div>
    </div>

    @if ($successMessage !== '')
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ $successMessage }}
        </p>
    @endif

    @if ($warningMessage !== '')
        <p class="rounded border border-heritage-yellow/60 bg-heritage-yellow/20 px-3 py-2 text-sm font-medium text-charcoal" role="status">
            {{ $warningMessage }}
        </p>
    @endif

    @if ($errorMessage !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
            {{ $errorMessage }}
        </p>
    @endif

    <div class="flex min-w-0 flex-col gap-4 xl:flex-row">

        {{-- ── Main column ─────────────────────────────────────────────── --}}
        <div class="min-w-0 flex-1 space-y-4">

            {{-- Status card --}}
            <section aria-label="{{ __('licence.panel.status_card') }}"
                     class="rounded border border-border-primary bg-white p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-charcoal">{{ __('licence.panel.status_card') }}</h2>
                        @if ($evaluation->failureKey !== null)
                            <p class="mt-0.5 text-xs font-medium text-heritage-red">{{ __($evaluation->failureKey) }}</p>
                        @endif
                    </div>
                    <x-status-pill :status="$pillStatus" :label="$state->label()" data-licence-state="{{ $state->value }}" />
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @if ($evaluation->expiresOn !== null)
                        <div class="rounded border border-border-primary bg-sand/20 p-3">
                            <dt class="text-xs text-charcoal/60">{{ __('licence.panel.expires_on') }}</dt>
                            <dd class="mt-0.5 text-sm font-semibold text-charcoal">{{ $evaluation->expiresOn->toDateString() }}</dd>
                        </div>
                    @endif

                    @if (! $evaluation->trusted)
                        <div class="rounded border border-border-primary bg-sand/20 p-3">
                            <dt class="text-xs text-charcoal/60">{{ __('licence.panel.trial_ends') }}</dt>
                            <dd class="mt-0.5 text-sm font-semibold text-charcoal">
                                {{ $evaluation->trialEndsOn?->toDateString() ?? __('licence.panel.trial_clock_unset') }}
                            </dd>
                        </div>
                    @endif

                    <div class="rounded border border-border-primary bg-sand/20 p-3">
                        <dt class="text-xs text-charcoal/60">{{ __('licence.panel.students_on_books') }}</dt>
                        <dd class="mt-0.5 text-sm font-semibold text-charcoal">{{ $evaluation->studentCount }}</dd>
                    </div>
                </dl>

                @if ($state === LicenceState::Trial && ! $evaluation->trusted)
                    <p class="mt-3 text-xs text-charcoal/60">{{ __('licence.panel.trial_intro') }}</p>
                @endif

                @if ($state === LicenceState::Grace || $state === LicenceState::Expiring)
                    <p class="mt-3 rounded border border-heritage-yellow/60 bg-heritage-yellow/10 px-3 py-2 text-xs text-charcoal">
                        {{ __('licence.panel.grace_note') }}
                    </p>
                @endif

                @if ($state === LicenceState::Enforced || $state === LicenceState::Revoked)
                    <p class="mt-3 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-xs text-charcoal">
                        {{ __('licence.panel.enforced_note') }}
                    </p>
                @endif
            </section>

            {{-- Details card, only when a verified licence exists --}}
            @if ($evaluation->trusted && $evaluation->licence !== null)
                <section aria-label="{{ __('licence.panel.details_card') }}"
                         class="rounded border border-border-primary bg-white p-4 sm:p-5">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('licence.panel.details_card') }}</h2>
                    <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div class="flex items-baseline justify-between gap-3 border-b border-border-primary/60 py-1.5">
                            <dt class="text-charcoal/60">{{ __('licence.panel.holder') }}</dt>
                            <dd class="font-medium text-charcoal">{{ is_string($payload['school'] ?? null) ? $payload['school'] : '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 border-b border-border-primary/60 py-1.5">
                            <dt class="text-charcoal/60">{{ __('licence.panel.edition') }}</dt>
                            <dd class="font-medium text-charcoal">{{ is_string($payload['edition'] ?? null) ? $payload['edition'] : '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 border-b border-border-primary/60 py-1.5">
                            <dt class="text-charcoal/60">{{ __('licence.panel.student_cap') }}</dt>
                            <dd class="font-medium text-charcoal">
                                {{ is_int($payload['student_cap'] ?? null) ? $payload['student_cap'] : __('licence.panel.unlimited') }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 border-b border-border-primary/60 py-1.5">
                            <dt class="text-charcoal/60">{{ __('licence.panel.source') }}</dt>
                            <dd class="font-medium text-charcoal">
                                {{ $evaluation->licence->source === Licence::SOURCE_ACTIVATION
                                    ? __('licence.panel.source_activation')
                                    : __('licence.panel.source_file') }}
                            </dd>
                        </div>
                    </dl>
                </section>
            @endif

            {{-- Import a licence file --}}
            <section aria-label="{{ __('licence.panel.import_card') }}"
                     class="rounded border border-border-primary bg-white p-4 sm:p-5">
                <h2 class="text-base font-semibold text-charcoal">{{ __('licence.panel.import_card') }}</h2>
                <p class="mt-0.5 text-xs text-charcoal/60">{{ __('licence.panel.import_help') }}</p>

                <textarea wire:model="importContents" rows="4" spellcheck="false"
                          placeholder="{{ __('licence.panel.import_placeholder') }}"
                          class="mt-3 w-full rounded border border-border-primary bg-sand/10 px-3 py-2 font-mono text-xs text-charcoal focus:border-primary focus:outline-none"></textarea>

                <div class="mt-3 flex justify-end">
                    <button type="button" wire:click="importFile"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        {{ __('licence.panel.import_button') }}
                    </button>
                </div>
            </section>

            {{-- Activate online --}}
            <section aria-label="{{ __('licence.panel.activate_card') }}"
                     class="rounded border border-border-primary bg-white p-4 sm:p-5">
                <h2 class="text-base font-semibold text-charcoal">{{ __('licence.panel.activate_card') }}</h2>
                <p class="mt-0.5 text-xs text-charcoal/60">{{ __('licence.panel.activate_help') }}</p>

                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                    <input type="text" wire:model="licenceKey" autocomplete="off" spellcheck="false"
                           placeholder="{{ __('licence.panel.activate_placeholder') }}"
                           class="w-full flex-1 rounded border border-border-primary bg-sand/10 px-3 py-2 font-mono text-sm text-charcoal focus:border-primary focus:outline-none" />
                    <button type="button" wire:click="activate"
                            class="shrink-0 rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        {{ __('licence.panel.activate_button') }}
                    </button>
                </div>
            </section>

            {{-- Deactivate --}}
            @if ($evaluation->licence !== null)
                <section aria-label="{{ __('licence.panel.deactivate_card') }}"
                         class="rounded border border-border-primary bg-white p-4 sm:p-5">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('licence.panel.deactivate_card') }}</h2>
                    <p class="mt-0.5 text-xs text-charcoal/60">{{ __('licence.panel.deactivate_help') }}</p>

                    <div class="mt-3 flex justify-end">
                        <button type="button" wire:click="deactivate"
                                wire:confirm="{{ __('licence.panel.deactivate_card') }}?"
                                class="rounded border border-heritage-red/50 px-4 py-2 text-sm font-semibold text-heritage-red hover:bg-heritage-red/10">
                            {{ __('licence.panel.deactivate_button') }}
                        </button>
                    </div>
                </section>
            @endif
        </div>

        {{-- ── Side column: the written commitment (mockup's status rail) ── --}}
        <aside class="w-full shrink-0 space-y-4 xl:w-80">
            <section aria-label="{{ __('licence.panel.never_blocked_title') }}"
                     class="rounded border border-border-primary bg-white p-4 sm:p-5">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-charcoal">
                    <span class="flex h-7 w-7 items-center justify-center rounded bg-primary/10 text-primary" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                    </span>
                    {{ __('licence.panel.never_blocked_title') }}
                </h2>
                <p class="mt-2 text-xs leading-relaxed text-charcoal/70">{{ __('licence.panel.never_blocked_body') }}</p>
            </section>
        </aside>
    </div>
</div>
