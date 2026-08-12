@props([
    'title' => null,
    'subtitle' => null,
    'footer' => true,
])

{{--
    The frame every auth screen in the reference set shares
    (login-welcome-back.png, forgot-password-reset.png,
    verify-your-account-otp.png): a green band closing on the gold wave, the
    crest and wordmark, the form on a cream field, and a green footer carrying
    the two reassurance lines.

    A component rather than a layout, because these screens run on the
    platform's existing `layouts.guest` shell - the staff login uses it too,
    and replacing it would restyle a page this work has no business touching.
--}}
<div class="min-h-screen bg-portal-cream">
    <div class="bg-portal-green">
        <div class="h-6"></div>
    </div>
    <x-portal.curve/>

    <div class="mx-auto w-full max-w-md px-5 pb-10">
        <div class="flex flex-col items-center gap-1 pt-6 text-center">
            <x-portal.crest size="xl"/>

            <p class="mt-3 font-serif text-2xl font-bold tracking-[0.18em] text-portal-green">
                {{ __('opes.shell.brand') }}
            </p>
            <p class="text-xs font-semibold tracking-[0.2em] text-portal-gold-deep">
                {{ __('opes.guardian_portal.brand_suffix') }}
            </p>
            <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.guardian_portal.tagline') }}</p>
        </div>

        @if ($title)
            <h1 class="mt-7 text-center text-2xl font-bold text-portal-green">{{ $title }}</h1>
        @endif

        @if ($subtitle)
            <p class="mt-1 text-center text-sm text-charcoal/65">{{ $subtitle }}</p>
        @endif

        <div class="mt-6">
            {{ $slot }}
        </div>
    </div>

    @if ($footer)
        <div class="mt-auto">
            <x-portal.curve/>
            <div class="bg-portal-green px-5 pb-8 pt-2">
                <div class="mx-auto flex max-w-md items-center justify-center gap-5 text-center">
                    <div class="flex items-center gap-2">
                        <x-portal.icon name="shield" bare size="md" class="text-portal-gold"/>
                        <span class="text-left text-xs leading-tight text-white/85">
                            {{ __('opes.guardian_portal.auth_safety_one') }}<br>
                            {{ __('opes.guardian_portal.auth_safety_two') }}
                        </span>
                    </div>

                    <span class="h-8 w-px bg-white/25" aria-hidden="true"></span>

                    <div class="flex items-center gap-2">
                        <x-portal.icon name="shield" bare size="md" class="text-portal-gold"/>
                        <span class="text-left text-xs leading-tight text-white/85">
                            {{ __('opes.guardian_portal.auth_secure_one') }}<br>
                            {{ __('opes.guardian_portal.auth_secure_two') }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
