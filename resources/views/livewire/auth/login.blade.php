{{--
    Built to mobile/login-welcome-back.png: crest and wordmark on a cream
    field, the green band closing on the gold wave, and the two reassurance
    lines in the footer.

    The COPY stays role-neutral. This page is the platform's only login - staff
    reach the back office through it too - so the design's "Login to your
    Parent Account" would be wrong for half the people who see it. The brand
    treatment is shared; the wording is not.

    Every wire binding is unchanged: `authenticate`, `email`, `password`,
    `remember`, and the demo block's `demoLogin()`. This is a restyle, not a
    rewrite - the auth path is the last thing that should be casually edited.
--}}
<x-portal.auth-frame :title="__('opes.guardian_portal.auth_welcome_back')"
                     :subtitle="__('opes.auth.sign_in')">

    {{-- The error sits ABOVE the form so a screen reader meets it before the
         fields, and so it is visible without scrolling on a small screen. --}}
    @if ($errors->any())
        <div role="alert" aria-live="assertive"
             class="mb-4 rounded-xl border-l-4 border-portal-danger bg-portal-danger-soft px-4 py-3 text-sm text-charcoal">
            <ul class="list-none space-y-1">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="authenticate"
          class="space-y-4 rounded-2xl border border-border-primary bg-white p-5 shadow-[0_2px_10px_rgba(0,45,23,0.06)]"
          novalidate>
        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-charcoal">
                {{ __('opes.auth.email') }}
            </label>

            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-charcoal/35">
                    <x-portal.icon name="mail" bare size="md"/>
                </span>

                <input id="email" name="email" type="email" wire:model="email"
                       autocomplete="username" required autofocus
                       @error('email') aria-invalid="true" @enderror
                       class="block w-full rounded-xl border border-border-primary bg-white py-3 pl-11 pr-3 text-charcoal
                              focus:border-primary focus:outline-none">
            </div>
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-charcoal">
                {{ __('opes.auth.password') }}
            </label>

            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-charcoal/35">
                    <x-portal.icon name="shield" bare size="md"/>
                </span>

                <input id="password" name="password" type="password" wire:model="password"
                       autocomplete="current-password" required
                       class="block w-full rounded-xl border border-border-primary bg-white py-3 pl-11 pr-3 text-charcoal
                              focus:border-primary focus:outline-none">
            </div>
        </div>

        <div class="flex items-center justify-between gap-3">
            <label for="remember" class="flex items-center gap-2 text-sm text-charcoal">
                <input id="remember" name="remember" type="checkbox" wire:model="remember"
                       class="h-4 w-4 rounded border-border-primary text-primary">
                {{ __('opes.auth.remember') }}
            </label>

            <a href="{{ route('portal.entry', 'reset') }}" class="text-sm font-semibold text-primary hover:underline">
                {{ __('opes.guardian_portal.auth_forgot') }}
            </a>
        </div>

        <button type="submit"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-portal-green px-4 py-3 text-sm font-semibold text-white
                       hover:brightness-110">
            <x-portal.icon name="shield" bare size="sm"/>
            {{ __('opes.auth.sign_in') }}
        </button>
    </form>

    {{-- One-click demo sign-in. Rendered only when the component says it is
         available, which requires BOTH the config flag and the local
         environment - see config/opes.php. On any real deployment this whole
         block is absent from the HTML, not merely hidden by CSS. --}}
    @if ($this->demoLoginAvailable())
        <div class="mt-5 rounded-2xl border border-dashed border-portal-gold bg-portal-gold/10 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.auth.demo_heading') }}
            </p>
            <p class="mt-1 text-xs text-charcoal/70">{{ __('opes.auth.demo_choose_role') }}</p>

            {{-- One button per configured identity. Each signs in as a REAL
                 user holding that role through Spatie, so what the visitor
                 then sees is the product's own permission checks answering -
                 not a demo mode. --}}
            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($this->demoIdentities() as $identity)
                    <button type="button" wire:click="demoLogin('{{ $identity['key'] }}')"
                            wire:key="demo-{{ $identity['key'] }}"
                            class="rounded-xl bg-portal-green px-3 py-2.5 text-left text-sm font-semibold text-white
                                   hover:brightness-110">
                        {{ __('opes.auth.demo_sign_in_as', ['role' => $identity['label']]) }}
                    </button>
                @endforeach
            </div>

            <p class="mt-2 text-xs text-charcoal/60">{{ __('opes.auth.demo_help') }}</p>
            <p class="mt-1 text-xs text-charcoal/60">{{ __('opes.auth.demo_rbac_help') }}</p>
        </div>
    @endif

    {{-- 00-core 9.3: no SMTP in most schools, so no self-service reset link.
         Say so plainly rather than offering a link that would never arrive. --}}
    <div class="mt-5 rounded-2xl border border-border-secondary bg-portal-tint px-4 py-3">
        <p class="text-sm font-semibold text-charcoal">{{ __('opes.auth.forgot') }}</p>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.auth.forgot_help') }}</p>
    </div>
</x-portal.auth-frame>
