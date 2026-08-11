{{-- /settings/branding - the school's one brand-colour choice. Heritage
     design system, PROGRESS.md §4a. A single-tenant-per-deployment platform:
     this is the ONE colour this school's install runs under, not a
     multi-tenant theme picker. --}}
<div class="min-w-0 max-w-2xl space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.nav.settings') }}</li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.branding.title') }}</li>
        </ol>
    </nav>

    <div>
        <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.branding.title') }}</h1>
        <p class="mt-1 text-sm text-text-secondary">{{ __('opes.branding.subtitle') }}</p>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-success/30 bg-success-bg px-4 py-3 text-sm font-medium text-success">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-xl border border-border-primary bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
            <div class="flex items-center gap-3">
                {{-- The real picker. type=color is a native OS swatch on every
                     platform this app runs on - no JS colour-picker library
                     needed for a single value. --}}
                <input
                    type="color"
                    wire:model.live="primaryColor"
                    aria-label="{{ __('opes.branding.color_input_label') }}"
                    class="h-14 w-14 cursor-pointer rounded-lg border border-border-primary p-1"
                >
                <div>
                    <input
                        type="text"
                        wire:model.live.debounce.400ms="primaryColor"
                        maxlength="7"
                        placeholder="#0B5A32"
                        aria-label="{{ __('opes.branding.hex_input_label') }}"
                        class="w-28 rounded-lg border border-border-primary px-2 py-1.5 font-mono text-sm uppercase text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                    @error('primaryColor')
                        <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex-1">
                <button
                    type="button"
                    wire:click="resetToHeritageGreen"
                    class="text-xs font-medium text-text-secondary underline decoration-dotted hover:text-primary"
                >
                    {{ __('opes.branding.reset') }}
                </button>
            </div>
        </div>

        <div class="doc-rule mt-5 border-t border-border-primary pt-5">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">
                {{ __('opes.branding.preview_label') }}
            </p>

            @if ($preview !== null)
                {{-- A miniature of the real shell so a school sees exactly
                     what picking this colour changes, without navigating
                     away and losing the unsaved value. --}}
                <div class="flex overflow-hidden rounded-lg border border-border-primary" style="height: 96px;">
                    <div class="flex w-28 flex-col justify-between p-2" style="background-color: {{ $preview['chrome'] }};">
                        <div class="h-2 w-14 rounded-full" style="background-color: {{ $preview['chromeLight'] }};"></div>
                        <div class="rounded px-2 py-1 text-[10px] font-semibold text-white" style="background-color: {{ $preview['chromeLight'] }};">
                            {{ __('opes.branding.preview_nav_item') }}
                        </div>
                    </div>
                    <div class="flex flex-1 items-center justify-center bg-sand p-3">
                        <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white" style="background-color: {{ $preview['primary'] }};" disabled>
                            {{ __('opes.branding.preview_button') }}
                        </button>
                    </div>
                </div>
            @else
                <p class="text-sm text-text-muted">{{ __('opes.branding.invalid_preview') }}</p>
            @endif
        </div>

        <div class="mt-5 flex justify-end">
            <button
                type="button"
                wire:click="save"
                class="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-chrome-light"
            >
                {{ __('opes.branding.save') }}
            </button>
        </div>
    </div>
</div>
