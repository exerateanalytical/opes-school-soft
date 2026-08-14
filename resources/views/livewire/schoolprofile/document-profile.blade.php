{{-- /settings/school-identity - the letterhead every printed document wears.
     school_document_profiles shipped with 0 rows and no writer, so every
     document printed bare; this screen is that writer's UI. --}}
<div class="min-w-0 max-w-4xl space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.nav.settings') }}</li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.school_identity.title') }}</li>
        </ol>
    </nav>

    <div>
        <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.school_identity.title') }}</h1>
        <p class="mt-1 text-sm text-text-secondary">{{ __('opes.school_identity.subtitle') }}</p>
    </div>

    @if ($fiscalProvisional)
        <div class="rounded-xl border border-heritage-yellow/60 bg-heritage-yellow/15 px-4 py-3 text-sm text-charcoal">
            <p class="font-semibold">{{ __('opes.school_identity.fiscal_provisional_title') }}</p>
            <p class="mt-1">{{ __('opes.school_identity.fiscal_provisional_body') }}</p>
            @can('ledger.configure')
                <a href="{{ route('tax.fiscal-identity') }}" class="mt-2 inline-block font-medium text-primary hover:underline">
                    {{ __('opes.school_identity.fiscal_provisional_action') }}
                </a>
            @endcan
        </div>
    @endif

    @if (session('status'))
        <div class="rounded-xl border border-success/30 bg-success-bg px-4 py-3 text-sm font-medium text-success">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-border-primary bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.school_identity.contacts') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'addressLine1' => 'address_line1', 'addressLine2' => 'address_line2',
                    'city' => 'city', 'region' => 'region', 'poBox' => 'po_box',
                    'phone' => 'phone', 'phoneAlt' => 'phone_alt', 'email' => 'email',
                    'website' => 'website', 'authorisationLine' => 'authorisation_line',
                ] as $model => $key)
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-charcoal">{{ __('opes.school_identity.'.$key) }}</span>
                        <input type="text" wire:model="{{ $model }}"
                               class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        @error($key) <span class="mt-1 block text-xs font-medium text-danger">{{ $message }}</span> @enderror
                    </label>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-border-primary bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.school_identity.state_header') }}
            </h2>
            <label class="mb-4 flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="stateHeaderEnabled">
                <span>{{ __('opes.school_identity.state_header_enabled') }}</span>
            </label>
            @if ($stateHeaderEnabled)
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        'ministryEn' => 'ministry_en', 'ministryFr' => 'ministry_fr',
                        'regionalDelegationEn' => 'regional_delegation_en',
                        'regionalDelegationFr' => 'regional_delegation_fr',
                        'divisionalDelegationEn' => 'divisional_delegation_en',
                        'divisionalDelegationFr' => 'divisional_delegation_fr',
                    ] as $model => $key)
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium text-charcoal">{{ __('opes.school_identity.'.$key) }}</span>
                            <input type="text" wire:model="{{ $model }}"
                                   class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            @error($key) <span class="mt-1 block text-xs font-medium text-danger">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-xl border border-border-primary bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.school_identity.marks') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'logoPath' => 'logo_path', 'crestPath' => 'crest_path',
                    'principalSignaturePath' => 'principal_signature_path',
                    'registrarSignaturePath' => 'registrar_signature_path',
                    'schoolStampPath' => 'school_stamp_path',
                ] as $model => $key)
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-charcoal">{{ __('opes.school_identity.'.$key) }}</span>
                        <input type="text" wire:model="{{ $model }}"
                               class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        @error($key) <span class="mt-1 block text-xs font-medium text-danger">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-charcoal">{{ __('opes.school_identity.default_document_language') }}</span>
                    <select wire:model="defaultDocumentLanguage"
                            class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="en">English</option>
                        <option value="fr">Français</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 self-end text-sm">
                    <input type="checkbox" wire:model="bilingualDocuments">
                    <span>{{ __('opes.school_identity.bilingual_documents') }}</span>
                </label>
            </div>
        </section>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                {{ __('opes.ui.save') }}
            </button>
            <p class="text-xs text-charcoal/55">{{ __('opes.school_identity.blank_means_omitted') }}</p>
        </div>
    </form>
</div>
