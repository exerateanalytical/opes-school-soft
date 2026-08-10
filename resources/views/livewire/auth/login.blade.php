<div>
    <h1 class="text-center text-xl font-semibold text-charcoal">{{ __('opes.auth.sign_in') }}</h1>

    {{-- The error sits ABOVE the form so a screen reader meets it before the
         fields, and so it is visible without scrolling on a small screen. --}}
    @if ($errors->any())
        <div role="alert" aria-live="assertive"
             class="mt-4 rounded border-l-4 border-heritage-red bg-heritage-red/5 px-4 py-3 text-sm text-charcoal">
            <ul class="list-none space-y-1">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="authenticate" class="mt-6 space-y-5" novalidate>
        <div>
            <label for="email" class="block text-sm font-medium text-charcoal">
                {{ __('opes.auth.email') }}
            </label>
            <input id="email" name="email" type="email" wire:model="email"
                   autocomplete="username" required autofocus
                   @error('email') aria-invalid="true" @enderror
                   class="mt-1 block w-full rounded border border-sand bg-white px-3 py-2 text-charcoal
                          focus:border-primary focus:outline-none">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-charcoal">
                {{ __('opes.auth.password') }}
            </label>
            <input id="password" name="password" type="password" wire:model="password"
                   autocomplete="current-password" required
                   class="mt-1 block w-full rounded border border-sand bg-white px-3 py-2 text-charcoal
                          focus:border-primary focus:outline-none">
        </div>

        <div class="flex items-center gap-2">
            <input id="remember" name="remember" type="checkbox" wire:model="remember"
                   class="h-4 w-4 rounded border-sand text-primary">
            <label for="remember" class="text-sm text-charcoal">
                {{ __('opes.auth.remember') }}
            </label>
        </div>

        <button type="submit"
                class="w-full rounded border border-primary bg-primary px-4 py-2.5 text-sm font-semibold text-white
                       hover:bg-primary/90">
            {{ __('opes.auth.sign_in') }}
        </button>
    </form>

    {{-- One-click demo sign-in. Rendered only when the component says it is
         available, which requires BOTH the config flag and the local
         environment - see config/opes.php. On any real deployment this whole
         block is absent from the HTML, not merely hidden by CSS. --}}
    @if ($this->demoLoginAvailable())
        <div class="mt-6 rounded border border-dashed border-heritage-yellow bg-heritage-yellow/10 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.auth.demo_heading') }}
            </p>
            <p class="mt-1 text-xs text-charcoal/70">{{ __('opes.auth.demo_choose_role') }}</p>

            {{-- One button per configured identity. Each signs in as a REAL
                 user holding that role through Spatie, so what the visitor
                 then sees is the product's own permission checks answering -
                 not a demo mode. --}}
            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($this->demoIdentities() as $identity)
                    <button type="button" wire:click="demoLogin('{{ $identity['key'] }}')"
                            wire:key="demo-{{ $identity['key'] }}"
                            class="rounded border border-chrome bg-chrome px-3 py-2 text-left text-sm font-semibold text-white
                                   hover:bg-chrome-light">
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
    <div class="mt-6 border-t border-sand pt-4">
        <p class="text-sm font-medium text-charcoal">{{ __('opes.auth.forgot') }}</p>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.auth.forgot_help') }}</p>
    </div>
</div>
