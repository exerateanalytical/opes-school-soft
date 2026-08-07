@props([
    'status' => 'ok',
    'label' => null,
])

@php
    $status = in_array($status, ['ok', 'amber', 'red'], true) ? $status : 'ok';

    // 09-ui section 10: colour is NEVER the only signal. Schools print these
    // pages on mono lasers and roughly one man in twelve cannot separate the
    // red from the green, so the word carries the meaning and the colour only
    // reinforces it.
    $text = $label ?? __('opes.ui.status_'.$status);

    $tone = match ($status) {
        'red' => 'border-heritage-red/40 bg-heritage-red/10 text-heritage-red',
        'amber' => 'border-heritage-yellow/60 bg-heritage-yellow/20 text-charcoal',
        default => 'border-primary/40 bg-primary/10 text-primary',
    };

    $dot = match ($status) {
        'red' => 'bg-heritage-red',
        'amber' => 'bg-heritage-yellow',
        default => 'bg-primary',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold '.$tone]) }}>
    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
    {{ $text }}
</span>
