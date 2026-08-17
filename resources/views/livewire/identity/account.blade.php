{{-- /account - the staff shell's own-account screen: who you are, and the
     only place a non-administrator can change their own password. --}}
<div class="min-w-0 max-w-4xl space-y-4">
    <div>
        <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.account.title') }}</h1>
        <p class="mt-1 text-sm text-text-secondary">{{ __('opes.account.subtitle') }}</p>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-success/30 bg-success-bg px-4 py-3 text-sm font-medium text-success-text">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-border-primary bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.account.who_you_are') }}
            </h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-charcoal/55">{{ __('opes.account.name') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $name }}</dd>
                </div>
                <div>
                    <dt class="text-charcoal/55">{{ __('opes.account.email') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $email }}</dd>
                </div>
                <div>
                    <dt class="text-charcoal/55">{{ __('opes.account.username') }}</dt>
                    <dd class="font-medium text-charcoal">
                        @if ($username !== '')
                            &commat;{{ $username }}
                        @else
                            <span class="font-normal text-charcoal/55">{{ __('opes.account.username_unset') }}</span>
                        @endif
                        {{-- Read-only on purpose: the tick is granted by an
                             administrator under `user.manage`, never claimed. --}}
                        <x-verified-badge :official="$isOfficial"/>
                    </dd>
                </div>
                <div>
                    <dt class="text-charcoal/55">{{ __('opes.account.roles') }}</dt>
                    <dd class="mt-1 flex flex-wrap gap-1.5">
                        @foreach ($roles as $role)
                            <x-status-pill :label="__('opes.roles.'.$role)"/>
                        @endforeach
                    </dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-charcoal/55">{{ __('opes.account.name_changes_via_admin') }}</p>

            {{-- The handle IS yours to set, unlike the name and email above:
                 it exists so colleagues and parents can message you without
                 knowing your email address. --}}
            <form wire:submit="saveUsername" class="mt-5 border-t border-border-primary pt-4">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-charcoal">{{ __('opes.account.choose_username') }}</span>
                    <input type="text" wire:model="username" autocomplete="username" maxlength="32"
                           class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <span class="mt-1 block text-xs text-charcoal/55">{{ __('opes.account.username_hint') }}</span>
                    @error('username') <span class="mt-1 block text-xs font-medium text-danger-text">{{ $message }}</span> @enderror
                </label>
                <button type="submit" class="mt-3 rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                    {{ __('opes.account.save_username') }}
                </button>
            </form>
        </section>

        <section class="rounded-xl border border-border-primary bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.account.change_password') }}
            </h2>
            <form wire:submit="changePassword" class="space-y-4">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-charcoal">{{ __('opes.account.current_password') }}</span>
                    <input type="password" wire:model="currentPassword" autocomplete="current-password"
                           class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    @error('currentPassword') <span class="mt-1 block text-xs font-medium text-danger-text">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-charcoal">{{ __('opes.account.new_password') }}</span>
                    <input type="password" wire:model="newPassword" autocomplete="new-password"
                           class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    @error('newPassword') <span class="mt-1 block text-xs font-medium text-danger-text">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-charcoal">{{ __('opes.account.confirm_new_password') }}</span>
                    <input type="password" wire:model="newPasswordConfirmation" autocomplete="new-password"
                           class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    @error('newPasswordConfirmation') <span class="mt-1 block text-xs font-medium text-danger-text">{{ $message }}</span> @enderror
                </label>
                <button type="submit" class="rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                    {{ __('opes.account.change_password') }}
                </button>
            </form>
        </section>
    </div>
</div>
