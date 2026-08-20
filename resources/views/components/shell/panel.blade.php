@props([
    'title' => '',
    'icon' => null,
    'iconTone' => 'text-primary',
    'footerLabel' => null,
    'footerUrl' => null,
])

{{--
    A dashboard panel: white card, optional titled header with a leading
    glyph, and an optional right-aligned footer link.

    Measured off `frontend images/super admin dashbaord.png`:
      surface   #FEFEFE on the #FBFAF7 ground
      title     cap 12 -> 17px, which is text-base at this app's 17px root
      footer    a link with a trailing arrow, right-aligned, on its own line

    The footer is a LINK or it is nothing: the reference draws "View all
    notifications ->" on every panel, and rendering that as inert text would
    put a control on screen that does not go anywhere.
--}}
<section {{ $attributes->merge(['class' => 'flex min-w-0 flex-col rounded-xl border border-shell-divider bg-shell-surface shadow-[0_1px_2px_rgba(16,24,40,0.05)]']) }}>
    @if ($title !== '')
        <h2 class="flex shrink-0 items-center gap-2.5 px-4 pt-3.5 pb-2 text-[17px] font-semibold text-charcoal">
            @if ($icon !== null)
                <x-shell.icon :name="$icon" class="h-[19px] w-[19px] {{ $iconTone }}"/>
            @endif
            {{ $title }}
        </h2>
    @endif

    <div class="min-h-0 flex-1 px-4 pb-2">
        {{ $slot }}
    </div>

    @if ($footerLabel !== null && $footerUrl !== null)
        <a href="{{ $footerUrl }}" wire:navigate
           class="flex shrink-0 items-center justify-end gap-1.5 px-4 pb-3 pt-1 text-[13px] font-medium text-charcoal/70 transition hover:text-primary">
            {{ $footerLabel }}
            <x-shell.icon name="arrow_right" class="h-[17px] w-[17px]"/>
        </a>
    @endif
</section>
