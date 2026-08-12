@props([
    'tone' => 'white',
    'padded' => true,
    'flush' => false,
])

{{--
    The card every reference screen is built from: white, generously rounded,
    a hairline border and a shadow soft enough to read as lift rather than as
    a drop shadow.

    `tone` covers the variants the designs use - the pale-green identity card
    at the top of Parent Profile, the deep-green summary panel on the fees
    dashboard, and the tinted alert strips.

    `flush` drops the padding so a card can hold a full-bleed header strip or
    a divided list, which several screens do.
--}}
@php
    $tones = [
        'white' => 'bg-white border-border-primary',
        'green' => 'bg-portal-tint border-border-secondary',
        'chrome' => 'bg-portal-green border-portal-green text-white',
        'gold' => 'bg-gold-100 border-gold-300',
        'danger' => 'bg-portal-danger-soft border-danger/30',
    ];
@endphp

<section {{ $attributes->merge([
    'class' => 'rounded-2xl border shadow-[0_2px_10px_rgba(0,45,23,0.06)] '
        .($tones[$tone] ?? $tones['white']).' '
        .($padded && ! $flush ? 'p-4 sm:p-5' : ''),
]) }}>
    {{ $slot }}
</section>
