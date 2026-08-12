<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'OPES SCHOOL') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

{{--
    A FULL-BLEED shell for the sign-in artwork.

    `layouts.guest` wraps its slot in `main.max-w-md` inside a centred flex
    column - correct for the narrow card it was built for, and fatal to a
    two-column composition that has to reach both edges of the viewport. This
    layout exists so the split screen can be built without touching that one,
    which the OTP and password-reset screens still render through.

    Everything else is deliberately identical to `guest`: same @vite entry,
    same Livewire hooks, same lang attribute. The difference is the wrapper,
    and only the wrapper.
--}}
<body class="min-h-screen bg-portal-green font-sans text-charcoal antialiased">
    {{ $slot }}

    @livewireScripts
</body>
</html>
