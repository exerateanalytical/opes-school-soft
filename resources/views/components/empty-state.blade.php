@props([
    'message' => '',
    'title' => null,   // optional bold line above the message
    'icon' => null,    // optional raw SVG; a neutral glyph is drawn when absent
])

{{-- An empty region must say why it is empty. A blank panel reads as a broken
     screen, and the operator's next move is a support call.

     That much was already true. What this component ALSO has to survive is
     being the whole screen: 56 callers, and on a permission-gated dashboard
     this is the only thing between the header and the footer. One grey
     sentence floating in a dashed rectangle is exactly the "dashboards empty
     or looking like they are made by an amateur" complaint, so the empty case
     gets a composed block - medallion, optional title, message, action - and
     reads as a deliberate state rather than a screen that failed to load.

     `title` and `icon` are optional and every existing `:message`-only caller
     renders unchanged. --}}
<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-border-primary bg-white px-6 py-10 text-center']) }}>

    <span class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-surface-green text-primary/70">
        @if ($icon !== null)
            {!! $icon !!}
        @else
            {{-- An open tray: "there is a place for these and nothing is in it",
                 which is the fact being reported. Deliberately not a warning
                 triangle - empty is not an error. --}}
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13h4l1.5 3h7L17 13h4"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M5.4 6.6A2 2 0 017.3 5.3h9.4a2 2 0 011.9 1.3L21 13v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4z"/>
            </svg>
        @endif
    </span>

    @if (is_string($title) && $title !== '')
        <p class="text-base font-semibold text-charcoal">{{ $title }}</p>
    @endif

    {{-- Capped and centred: these messages carry real reasoning ("no postable
         class-5 treasury account exists yet, so there is no float to report")
         and a 1100px-wide single line of it is unreadable. --}}
    <p class="mx-auto mt-1 max-w-prose text-sm leading-relaxed text-charcoal/60">{{ $message }}</p>

    @isset($action)
        <div class="mt-5 flex flex-wrap justify-center gap-2">
            {{ $action }}
        </div>
    @endisset
</div>
