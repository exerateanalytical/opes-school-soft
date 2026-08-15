{{-- The shared shell for EVERY settings screen (/settings/school-identity,
     /settings/branding, /settings/tax, ...). Before this each screen was a
     flat list of inputs with a Save button at the bottom that saved
     silently, so an operator could not tell a saved screen from an unsaved
     one, and a stray navigation discarded the lot.

     Three things it guarantees, in one place so the six screens cannot
     diverge:
       1. a STICKY save bar that is always reachable on a long form;
       2. a DIRTY marker plus a beforeunload guard, so leaving with unsaved
          edits requires a deliberate answer;
       3. a success toast driven by the `settings-saved` browser event the
          component dispatches, which also clears the dirty flag.

     `sticky bottom-0` rather than `fixed` with a sidebar offset: the bar
     lives INSIDE the form's own column, so it needs no knowledge of the
     shell's sidebar width (and the root font-size is 17px, which makes any
     hard-coded Tailwind width name a lie). --}}
@props([
    'title',
    'description' => null,
    'submit' => 'save',
    'cancel' => null,
])
<div class="min-w-0 max-w-4xl"
     x-data="{ dirty: false, toast: false }"
     x-on:input="dirty = true"
     x-on:change="dirty = true"
     x-on:settings-saved.window="dirty = false; toast = true; setTimeout(() => toast = false, 4000)"
     x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">

    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li><a href="{{ route('settings.index') }}" class="hover:text-primary hover:underline">{{ __('opes.nav.settings') }}</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $title }}</li>
        </ol>
    </nav>

    <div class="mt-3">
        <h1 class="text-2xl font-bold text-charcoal">{{ $title }}</h1>
        @if ($description !== null)
            <p class="mt-1 text-sm text-text-secondary">{{ $description }}</p>
        @endif
    </div>

    @isset($banner)
        <div class="mt-4">{{ $banner }}</div>
    @endisset

    {{-- The toast. `role="status"` so a screen reader announces the save;
         a flash message that only appears visually is not a confirmation. --}}
    <div x-show="toast" x-cloak role="status"
         class="fixed right-4 top-20 z-50 rounded-lg border border-success/30 bg-success-bg px-4 py-3 text-sm font-medium text-success shadow-lg">
        {{ __('opes.ui.saved') }}
    </div>

    <form wire:submit="{{ $submit }}" class="mt-4 space-y-6">
        {{ $slot }}

        <div class="sticky bottom-0 -mx-4 border-t border-border-primary bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-b-xl">
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit"
                        class="rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                    <span wire:loading.remove wire:target="{{ $submit }}">{{ __('opes.ui.save') }}</span>
                    <span wire:loading wire:target="{{ $submit }}">{{ __('opes.ui.saving') }}</span>
                </button>

                @if ($cancel !== null)
                    <button type="button" wire:click="{{ $cancel }}" x-on:click="dirty = false"
                            class="rounded-lg border border-border-primary px-4 py-2 text-sm font-medium text-charcoal transition hover:bg-sand">
                        {{ __('opes.ui.cancel') }}
                    </button>
                @endif

                <span x-show="dirty" x-cloak class="text-xs font-medium text-warning">
                    {{ __('opes.ui.unsaved_changes') }}
                </span>
            </div>
        </div>
    </form>
</div>
