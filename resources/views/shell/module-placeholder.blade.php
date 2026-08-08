@component('layouts.app')
    {{-- A scheduled module's landing page, served at the SAME URL the real
         module will later occupy so bookmarks survive it shipping. Contains
         no data by design: everything on it is a translation string, so
         there is nothing here to fabricate and nothing to gate beyond auth. --}}
    <div class="mx-auto max-w-2xl">
        <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="min-w-0">
            <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
                <li>{{ __('opes.users.breadcrumb_dashboard') }}</li>
                <li aria-hidden="true" class="text-charcoal/30">/</li>
                <li aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.nav.'.$moduleKey) }}</li>
            </ol>
        </nav>

        <div class="mt-6 rounded border border-sand bg-white p-8 text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-heritage-yellow/15">
                <svg class="h-7 w-7 text-heritage-yellow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/>
                    <path stroke-linecap="round" d="M12 7v5l3 3"/>
                </svg>
            </span>

            <h1 class="mt-4 text-xl font-semibold text-charcoal">{{ __('opes.nav.'.$moduleKey) }}</h1>

            <p class="mt-2 text-sm font-medium uppercase tracking-wide text-heritage-yellow">
                {{ __('opes.placeholder.chip') }}
            </p>

            <p class="mx-auto mt-3 max-w-prose text-sm leading-relaxed text-charcoal/70">
                {{ __('opes.placeholder.body', ['module' => __('opes.nav.'.$moduleKey)]) }}
            </p>

            <a href="/dashboard"
               class="mt-6 inline-flex items-center gap-2 rounded border border-primary bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                {{ __('opes.placeholder.back_to_dashboard') }}
            </a>
        </div>
    </div>
@endcomponent
