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
<body class="min-h-screen bg-ivory font-sans text-charcoal antialiased">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <main class="w-full max-w-md">
            <div class="overflow-hidden rounded-lg border border-sand bg-white shadow-sm">
                {{-- Chrome-green band. Red and yellow stay accents: the star is
                     the only heritage-yellow mark on the page. --}}
                <div class="flex items-center gap-3 bg-chrome px-6 py-5">
                    <svg class="h-7 w-7 shrink-0 text-heritage-yellow" viewBox="0 0 24 24" fill="currentColor"
                         aria-hidden="true">
                        <path d="M12 2.2l2.72 6.6 7.13.53-5.44 4.6 1.7 6.94L12 17.1l-6.11 3.77 1.7-6.94-5.44-4.6 7.13-.53L12 2.2z"/>
                    </svg>
                    <span class="text-lg font-semibold text-white">OPES</span>
                    <span class="text-sm font-medium tracking-[0.35em] text-white/80">SCHOOL</span>
                </div>

                <div class="px-6 py-7">
                    {{ $slot }}
                </div>
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>
