<div class="min-w-0 space-y-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.account_title') }}</h1>
        @if ($guardian)
            <p class="mt-1 text-sm text-charcoal/70">{{ $guardian->fullName() }}</p>
        @endif
    </div>

    @if (session('portal-status'))
        <p class="rounded border border-success/30 bg-success-bg px-4 py-2 text-sm text-success">{{ session('portal-status') }}</p>
    @endif

    {{--
        Row 29 only. The authorization flags (has_custody, receives_reports,
        receives_invoices, is_fee_payer) are ABSENT rather than disabled: row 30
        grants them to nobody, because a guardian who could edit their own flags
        could grant themselves every other row in the matrix. The Action refuses
        and audits an attempt independently of this form.
    --}}
    <form wire:submit="save" class="space-y-4">
        <section aria-labelledby="portal-account-contact" class="rounded border border-border-primary bg-white p-4 shadow-sm">
            <h2 id="portal-account-contact" class="text-sm font-semibold text-charcoal">
                {{ __('opes.guardian_portal.account_contact') }}
            </h2>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['phone', 'account_phone', 'tel'],
                    ['alternativePhone', 'account_alt_phone', 'tel'],
                    ['email', 'account_email', 'email'],
                    ['addressLine', 'account_address', 'text'],
                    ['city', 'account_city', 'text'],
                    ['region', 'account_region', 'text'],
                    ['occupation', 'account_occupation', 'text'],
                    ['employer', 'account_employer', 'text'],
                ] as [$field, $labelKey, $inputType])
                    <div>
                        <label for="portal-{{ $field }}" class="block text-xs font-medium text-charcoal/70">
                            {{ __('opes.guardian_portal.'.$labelKey) }}
                        </label>
                        <input id="portal-{{ $field }}" type="{{ $inputType }}" wire:model="{{ $field }}"
                               class="mt-1 w-full rounded border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none">
                        @error($field)
                            <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="portal-account-notify" class="rounded border border-border-primary bg-white p-4 shadow-sm">
            <h2 id="portal-account-notify" class="text-sm font-semibold text-charcoal">
                {{ __('opes.guardian_portal.account_notify') }}
            </h2>

            <div class="mt-3 space-y-2">
                @foreach ([
                    ['notifySms', 'account_notify_sms'],
                    ['notifyEmail', 'account_notify_email'],
                    ['notifyPush', 'account_notify_push'],
                ] as [$field, $labelKey])
                    <label class="flex items-center gap-2 text-sm text-charcoal">
                        <input type="checkbox" wire:model="{{ $field }}"
                               class="h-4 w-4 rounded border-border-primary text-primary focus:ring-primary">
                        {{ __('opes.guardian_portal.'.$labelKey) }}
                    </label>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-charcoal/60">{{ __('opes.guardian_portal.account_school_managed') }}</p>
            <button type="submit"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-chrome-light">
                {{ __('opes.guardian_portal.account_save') }}
            </button>
        </div>
    </form>
</div>
