<div class="mx-auto max-w-4xl space-y-6">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.users.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li><a href="{{ route('users.index') }}" class="hover:text-primary">{{ __('opes.users.breadcrumb_users') }}</a></li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.api_tokens.title') }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.api_tokens.title') }}</h1>
        <span class="rounded border border-border-primary bg-sand/60 px-2 py-1 text-xs text-charcoal/70">
            {{ $user->name }} &middot; {{ $user->email }}
        </span>
    </div>

    {{-- Shown exactly once, straight after creation. Reloading loses it by
         design: only the SHA-256 hash is stored. --}}
    @if ($plainTextToken !== null)
        <div class="rounded-lg border border-heritage-yellow/70 bg-heritage-yellow/10 p-4" role="alert">
            <h2 class="text-sm font-semibold text-charcoal">{{ __('opes.api_tokens.copy_now_title') }}</h2>
            <p class="mt-1 text-xs text-charcoal/70">{{ __('opes.api_tokens.copy_now_hint') }}</p>
            <code data-testid="plain-token"
                  class="mt-2 block select-all break-all rounded border border-border-primary bg-white px-3 py-2 font-mono text-sm text-charcoal">{{ $plainTextToken }}</code>
        </div>
    @endif

    <form wire:submit="createToken" class="space-y-5 rounded-lg border border-border-primary bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.api_tokens.create_title') }}
        </h2>

        <label for="token-name" class="flex max-w-md flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.api_tokens.name_label') }}</span>
            <input id="token-name" type="text" wire:model="name"
                   placeholder="{{ __('opes.api_tokens.name_placeholder') }}"
                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
            @error('name')
                <span class="text-xs text-heritage-red">{{ $message }}</span>
            @enderror
        </label>

        <fieldset class="space-y-2">
            <legend class="text-xs font-medium text-charcoal/70">{{ __('opes.api_tokens.abilities_label') }}</legend>
            <p class="text-xs text-charcoal/50">{{ __('opes.api_tokens.abilities_hint') }}</p>
            <div class="grid grid-cols-1 gap-1 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($abilityOptions as $ability)
                    <label for="ability-{{ $ability->value }}"
                           class="flex items-center gap-2 rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal hover:border-primary/50">
                        <input id="ability-{{ $ability->value }}" type="checkbox"
                               value="{{ $ability->value }}" wire:model="abilities"
                               class="rounded border-border-primary text-primary focus:ring-primary/50"/>
                        <span class="flex flex-col">
                            <span>{{ $ability->label(app()->getLocale()) }}</span>
                            <span class="font-mono text-[10px] text-charcoal/50">{{ $ability->value }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('abilities')
                <span class="text-xs text-heritage-red">{{ $message }}</span>
            @enderror
        </fieldset>

        <div class="flex items-center gap-2 border-t border-border-primary pt-5">
            <button type="submit"
                    class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ __('opes.api_tokens.create_button') }}
            </button>
            <a href="{{ route('users.index') }}"
               class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ __('opes.users.cancel') }}
            </a>
        </div>
    </form>

    <div class="overflow-hidden rounded-lg border border-border-primary bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-border-primary text-sm">
                <caption class="sr-only">{{ __('opes.api_tokens.table_caption') }}</caption>
                <thead>
                    <tr class="bg-chrome text-white">
                        <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">{{ __('opes.api_tokens.col_name') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">{{ __('opes.api_tokens.col_abilities') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">{{ __('opes.api_tokens.col_last_used') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">{{ __('opes.api_tokens.col_created') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.api_tokens.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary/70">
                    @forelse ($tokens as $token)
                        <tr wire:key="token-{{ $token->id }}">
                            <td class="px-4 py-2.5 font-medium text-charcoal">{{ $token->name }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($token->abilities ?? [] as $tokenAbility)
                                        <span class="rounded-full border border-primary/30 bg-primary/10 px-2 py-0.5 font-mono text-[10px] text-primary">
                                            {{ $tokenAbility }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-charcoal/70">
                                {{ $token->last_used_at?->diffForHumans() ?? __('opes.api_tokens.never_used') }}
                            </td>
                            <td class="px-4 py-2.5 text-charcoal/70">{{ $token->created_at?->toDateString() }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <button type="button" wire:click="revoke({{ $token->id }})"
                                        wire:confirm="{{ __('opes.api_tokens.revoke_confirm') }}"
                                        class="rounded border border-heritage-red/50 px-3 py-1 text-xs font-medium text-heritage-red hover:bg-heritage-red/10">
                                    {{ __('opes.api_tokens.revoke') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-charcoal/60">
                                {{ __('opes.api_tokens.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
