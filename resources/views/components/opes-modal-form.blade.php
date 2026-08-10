@props([
    'wireModel' => null,
    'open' => false,
    'title' => '',
    'maxWidth' => 'lg',
])

{{--
    The universal popup-form shell. A caller wraps a Livewire form component
    in this to get: a real accessible dialog (focus trap, ESC to close,
    backdrop click, scroll lock), and nothing else - the component inside
    still owns its own fields, validation and save/hold/discard buttons via
    the AutosavesDraft trait.

        <x-opes-modal-form wire-model="showForm" :open="$showForm" title="...">

    `open` (the actual boolean) controls whether the dialog exists in the DOM
    AT ALL, via Blade's own @if - so Livewire's morph adds/removes it on the
    next render, which is the one thing Livewire is unambiguously reliable
    at. `wireModel` (the property NAME) is only used to write false back to
    the server from the close/backdrop/ESC actions.

    Earlier version tried `x-show="$wire.{{ $wireModel }}"` and then
    `@entangle`, both live-toggling visibility purely on the Alpine side.
    Verified against a real browser session: a server round-trip correctly
    flipped the Livewire property to false in both cases, but the dialog's
    computed `display` never changed - Alpine's x-show effect was not
    reliably re-firing across a morph on this nested-Blade-component
    structure. Letting Blade's own @if gate DOM presence removes that
    failure mode entirely; Alpine here only drives the OPEN transition, on
    a freshly-inserted node, which needs no cross-request reactivity at all.
--}}
@php
    $maxWidthClass = match ($maxWidth) {
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
        '3xl' => 'sm:max-w-3xl',
        default => 'sm:max-w-lg',
    };
@endphp

@if ($open)
    <div
        x-data="{ entered: false }"
        x-init="$nextTick(() => entered = true)"
        x-on:keydown.escape.window="$wire.set('{{ $wireModel }}', false)"
        class="fixed inset-0 z-40 flex items-end justify-center sm:items-center"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $title }}"
    >
        <div
            x-show="entered"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 bg-charcoal/50"
            wire:click="$set('{{ $wireModel }}', false)"
            aria-hidden="true"
        ></div>

        <div
            x-show="entered"
            x-trap.noscroll="entered"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            class="relative flex max-h-[90vh] w-full flex-col overflow-hidden rounded-t-lg bg-white shadow-xl sm:rounded-lg sm:m-4 {{ $maxWidthClass }}"
        >
            <header class="flex shrink-0 items-center justify-between border-b border-border-primary px-5 py-3">
                <h2 class="text-base font-semibold text-charcoal">{{ $title }}</h2>
                <button type="button"
                        wire:click="$set('{{ $wireModel }}', false)"
                        class="rounded p-1.5 text-charcoal/50 hover:bg-sand hover:text-charcoal">
                    <span class="sr-only">{{ __('opes.modal.close') }}</span>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/>
                    </svg>
                </button>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                {{ $slot }}
            </div>
        </div>
    </div>
@endif
