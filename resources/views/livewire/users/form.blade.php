<div class="max-w-xl space-y-6">
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

    <form wire:submit="save" class="space-y-4 rounded border border-sand bg-white p-4">
        <div class="flex flex-col gap-1">
            <label for="user-name" class="text-sm font-medium text-charcoal">{{ __('opes.users.name_label') }}</label>
            <input id="user-name" type="text" wire:model="name"
                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal"/>
            @error('name')
                <p class="text-xs text-heritage-red">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex flex-col gap-1">
            <label for="user-email" class="text-sm font-medium text-charcoal">{{ __('opes.users.email_label') }}</label>
            <input id="user-email" type="email" wire:model="email"
                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal"/>
            @error('email')
                <p class="text-xs text-heritage-red">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex flex-col gap-1">
            <label for="user-role" class="text-sm font-medium text-charcoal">{{ __('opes.users.role_field_label') }}</label>
            <select id="user-role" wire:model="role"
                    class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.users.role_placeholder') }}</option>
                @foreach ($roleOptions as $roleOption)
                    <option value="{{ $roleOption->value }}">{{ $roleOption->label(app()->getLocale()) }}</option>
                @endforeach
            </select>
            @error('role')
                <p class="text-xs text-heritage-red">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex flex-col gap-1">
            <label for="user-password" class="text-sm font-medium text-charcoal">{{ __('opes.users.password_label') }}</label>
            <input id="user-password" type="password" wire:model="password"
                   class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal"/>
            @error('password')
                <p class="text-xs text-heritage-red">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ __('opes.users.save') }}
            </button>
            <a href="{{ route('users.index') }}"
               class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ __('opes.users.cancel') }}
            </a>
        </div>
    </form>
</div>
