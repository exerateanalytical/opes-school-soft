@props([
    'official' => false,
    'label' => null,
])

{{--
    The official-account tick, shown beside a sender's name wherever a message
    displays who sent it.

    Its job is anti-impersonation: a guardian receiving "pay your fees to this
    number" needs to tell the Bursar's real account from a parent who named
    themselves "Bursar". So it renders ONLY from users.is_official, which no
    user can set on themselves (Identity\Actions\MarkUserOfficial gates on
    `user.manage`).

    09-ui section 10: colour is never the only signal. The glyph is a check
    mark with its own shape, and the accessible name says "official school
    account" in words - a screen reader and a mono print both still carry the
    meaning with the blue removed. `badge-blue` is the existing token used for
    the Administrator pill; social platforms trained everyone that a verified
    tick is blue and there is no reason to be original about it.
--}}
@if ($official)
    @php $text = $label ?? __('opes.messages_screen.official_account'); @endphp

    <svg {{ $attributes->merge(['class' => 'inline-block h-3.5 w-3.5 shrink-0 align-text-bottom text-badge-blue']) }}
         viewBox="0 0 24 24" fill="currentColor" role="img"
         aria-label="{{ $text }}"><title>{{ $text }}</title>
        <path fill-rule="evenodd" clip-rule="evenodd"
              d="M12 1.5l2.47 2.06 3.2-.35 1.25 2.97 2.97 1.25-.35 3.2L23.6 12l-2.06 2.47.35 3.2-2.97 1.25-1.25 2.97-3.2-.35L12 22.5l-2.47-2.06-3.2.35-1.25-2.97-2.97-1.25.35-3.2L.4 12l2.06-2.47-.35-3.2 2.97-1.25L6.33 2.11l3.2.35L12 1.5zm4.53 7.32a1 1 0 00-1.42-1.41l-4.4 4.4-1.82-1.82a1 1 0 10-1.42 1.42l2.53 2.53a1 1 0 001.42 0l5.11-5.12z"/>
    </svg>
@endif
