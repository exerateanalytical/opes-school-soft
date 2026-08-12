@props([
    'name' => '?',
    'size' => 'md',
    'photo' => null,
    'tone' => 'chrome',
    'ring' => false,
])

{{--
    The circular portrait the reference screens use for the parent and each
    child.

    `photo` takes a URL (the guardian-scoped photo route). When it is absent -
    or when the file is missing and the route 404s - the initials show instead.
    `onerror` handles the second case: a broken-image glyph beside a child's
    name looks like the record itself is damaged, which is a worse lie than a
    monogram.

    The initials are a genuine fallback, not a placeholder to be replaced
    later: most schools will not have a photograph for every parent.
--}}
@php
    $box = match ($size) {
        'sm' => 'h-9 w-9 text-xs',
        'lg' => 'h-16 w-16 text-lg',
        'xl' => 'h-20 w-20 text-2xl',
        default => 'h-11 w-11 text-sm',
    };

    $tones = [
        'chrome' => 'bg-portal-green text-white',
        'green' => 'bg-portal-tint text-primary',
        'gold' => 'bg-gold-100 text-gold-700',
    ];

    $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<span {{ $attributes->merge([
    'class' => 'relative flex shrink-0 items-center justify-center overflow-hidden rounded-full font-semibold '
        .$box.' '.($tones[$tone] ?? $tones['chrome']).' '
        .($ring ? 'ring-2 ring-portal-gold ring-offset-2 ring-offset-white' : ''),
]) }}>
    @if ($photo)
        <img src="{{ $photo }}" alt=""
             class="absolute inset-0 h-full w-full object-cover"
             onerror="this.remove()">
    @endif

    <span aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
</span>
