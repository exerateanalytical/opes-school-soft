<div class="min-w-0 space-y-4">
    <div class="min-w-0">
        <a href="{{ route('portal.account.settings') }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {{ __('opes.guardian_portal.account_settings_title') }}
        </a>

        <h1 class="mt-1 text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.account_notify') }}</h1>
    </div>

    @if (session('portal-status'))
        <p class="rounded border border-success/30 bg-success-bg px-4 py-2 text-sm text-success">{{ session('portal-status') }}</p>
    @endif

    <form wire:submit="save" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <fieldset>
            <legend class="text-sm font-semibold text-charcoal">{{ __('opes.guardian_portal.account_notify') }}</legend>

            <div class="mt-3 divide-y divide-border-secondary">
                @foreach ([
                    ['notifySms', __('opes.guardian_portal.account_notify_sms')],
                    ['notifyEmail', __('opes.guardian_portal.account_notify_email')],
                    ['notifyPush', __('opes.guardian_portal.account_notify_push')],
                ] as [$field, $label])
                    <label class="flex items-center justify-between gap-4 py-3 text-sm text-charcoal">
                        <span>{{ $label }}</span>
                        <input type="checkbox" wire:model="{{ $field }}"
                               class="h-5 w-5 rounded border-border-primary text-primary focus:ring-primary">
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="mt-4 flex justify-end">
            <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-chrome-light">
                {{ __('opes.guardian_portal.account_save') }}
            </button>
        </div>
    </form>

    {{-- Per-TYPE preferences and quiet hours are in the reference design but
         are P1 (spec §7). Stated, not rendered as switches that would do
         nothing. --}}
    <p class="rounded border border-border-secondary bg-surface-green px-4 py-3 text-sm text-charcoal/70">
        {{ __('opes.guardian_portal.account_notifications_hint') }}
    </p>
</div>
