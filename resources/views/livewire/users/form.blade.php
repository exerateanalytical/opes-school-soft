<div class="mx-auto max-w-2xl space-y-6">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.users.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li><a href="{{ route('users.index') }}" class="hover:text-primary">{{ __('opes.users.breadcrumb_users') }}</a></li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.users.form_title') }}</li>
        </ol>
    </nav>

    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.users.form_title') }}</h1>

    <form wire:submit="save" class="space-y-6 rounded-lg border border-border-primary bg-white p-6 shadow-sm">
        <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
            <label for="user-name" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.name_label') }}</span>
                <input id="user-name" type="text" wire:model="name"
                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                @error('name')
                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                @enderror
            </label>

            <label for="user-email" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.email_label') }}</span>
                <input id="user-email" type="email" wire:model="email"
                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                @error('email')
                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                @enderror
            </label>

            <label for="user-role" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.role_field_label') }}</span>
                <select id="user-role" wire:model="role"
                        class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">{{ __('opes.users.role_placeholder') }}</option>
                    @foreach ($roleOptions as $roleOption)
                        <option value="{{ $roleOption->value }}">{{ $roleOption->label(app()->getLocale()) }}</option>
                    @endforeach
                </select>
                @error('role')
                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                @enderror
            </label>

            <label for="user-password" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.users.password_label') }}</span>
                <input id="user-password" type="password" wire:model="password"
                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                @error('password')
                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                @enderror
            </label>
        </div>

        <div class="flex items-center gap-2 border-t border-border-primary pt-5">
            <button type="submit"
                    class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ __('opes.users.save') }}
            </button>
            <a href="{{ route('users.index') }}"
               class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ __('opes.users.cancel') }}
            </a>
        </div>
    </form>
</div>
