@props([
    'label' => '',
    'value' => null,
    'tone' => 'green',
    'icon' => 'dashboard',
    'note' => null,
    'noteTone' => 'text-success',
    'footerLabel' => null,
    'footerUrl' => null,
])

{{--
    A KPI card from the reference's top strip.

    MEASURED off `frontend images/super admin dashbaord.png`:
      card height       117..242            -> 126px
      left padding      289 -> 308           = 19px
      top padding       117 -> 140           = 23px
      icon disc         x 308..357, y 140..189 - a filled circle's bounding
                        box IS its diameter, so 50 x 50 exactly
      label             cap  9 -> 13px
      value             cap 19 -> 26px  (text-2xl at the 17px root = 25.5px)
      footer link       cap  8 -> 12px

    The disc is a SOLID colour with a white glyph, not the pale tinted wash
    the older x-kpi-card uses. Both now exist; this one is for screens built
    to the new references, and x-kpi-card is untouched for every screen
    already using it.

    A null $value renders an em dash, never a zero: "no register has been
    taken today" and "nobody attended today" are different facts and a 0
    tells the reader the second one.
--}}
@php
    $tones = [
        'green' => 'bg-shell-disc',
        'gold' => 'bg-[#D19C14]',
        'red' => 'bg-shell-alert',
    ];

    $discClass = $tones[$tone] ?? $tones['green'];
@endphp

<div {{ $attributes->merge(['class' => 'flex min-w-0 flex-col rounded-xl border border-shell-divider bg-shell-surface px-[19px] pt-[23px] pb-[4px] shadow-[0_1px_2px_rgba(16,24,40,0.05)]']) }}>
    <div class="flex min-w-0 items-start gap-3">
        <span class="flex h-[50px] w-[50px] shrink-0 items-center justify-center rounded-full {{ $discClass }}">
            <x-shell.icon :name="$icon" class="h-[25px] w-[25px] text-white"/>
        </span>

        <div class="min-w-0 flex-1 pt-0.5">
            {{-- WRAPS, never truncates. The reference sets "Fees Collection
                 (This Month)" over two lines, and truncating it produced
                 "Fees Collecti..." - a label the reader cannot finish. --}}
            <p class="text-[13px] leading-tight text-balance text-charcoal/75">{{ $label }}</p>
            <p class="mt-1 truncate text-[26px] font-bold leading-none tracking-tight text-charcoal">
                {{ $value ?? '—' }}
            </p>
        </div>
    </div>

    <div class="mt-auto pt-2.5">
        @if ($footerLabel !== null && $footerUrl !== null)
            <a href="{{ $footerUrl }}" wire:navigate
               class="flex items-center justify-between gap-2 text-[12px] text-charcoal/70 transition hover:text-primary">
                <span class="truncate">{{ $footerLabel }}</span>
                <x-shell.icon name="arrow_right" class="h-[17px] w-[17px] shrink-0"/>
            </a>
        @elseif ($note !== null)
            <p class="truncate text-[12px] font-medium {{ $noteTone }}">{{ $note }}</p>
        @endif
    </div>
</div>
