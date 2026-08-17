{{-- /settings/whatsapp - the school's own Meta WhatsApp Business credentials.

     The status banner is first on the page on purpose: the question this
     screen gets opened for is "is it on?", and an operator must not have to
     infer that from whether the fields look filled in. --}}
<div>
    <x-settings-form :title="__('opes.whatsapp.title')"
                     :description="__('opes.whatsapp.subtitle')"
                     submit="save">

        @if ($isConfigured)
            <div role="status" class="rounded-xl border border-success/40 bg-success-bg px-4 py-3 text-sm text-charcoal">
                <p class="font-semibold">{{ __('opes.whatsapp.status_connected') }}</p>
                <p class="mt-1 text-xs">{{ __('opes.whatsapp.status_connected_body', ['version' => $endpointVersion]) }}</p>
            </div>
        @else
            <div role="alert" class="rounded-xl border border-warning/40 bg-warning-bg px-4 py-3 text-sm text-charcoal">
                <p class="font-semibold">{{ __('opes.whatsapp.status_not_connected') }}</p>
                <p class="mt-1">{{ __('opes.whatsapp.status_not_connected_body', ['reason' => $missingReason]) }}</p>
                <p class="mt-2 text-xs">{{ __('opes.whatsapp.status_inert_note') }}</p>
            </div>
        @endif

        <x-settings-fieldset :heading="__('opes.whatsapp.credentials')"
                             :hint="__('opes.whatsapp.credentials_hint')">

            <x-settings-field :label="__('opes.whatsapp.access_token')"
                              :hint="__('opes.whatsapp.access_token_hint')"
                              :error="$errors->first('accessToken')">
                {{-- type=password and never re-rendered with a value: this
                     screen gets shown on a projector during handover, and a
                     permanent System User token in the page source is a
                     credential leak that outlives the meeting. --}}
                <input type="password" wire:model="accessToken" autocomplete="off" spellcheck="false"
                       placeholder="{{ $tokenIsSet ? __('opes.whatsapp.token_set_placeholder') : __('opes.whatsapp.token_empty_placeholder') }}"
                       class="w-full rounded-lg border border-border-primary px-3 py-2 font-mono text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">

                @if ($tokenIsSet)
                    <button type="button" wire:click="clearToken"
                            class="mt-1 text-xs font-medium text-danger-text hover:underline">
                        {{ __('opes.whatsapp.clear_token') }}
                    </button>
                @endif
            </x-settings-field>

            <x-settings-field :label="__('opes.whatsapp.phone_number_id')"
                              :hint="__('opes.whatsapp.phone_number_id_hint')"
                              :error="$errors->first('phoneNumberId')">
                <input type="text" wire:model="phoneNumberId" inputmode="numeric" spellcheck="false"
                       class="w-full rounded-lg border border-border-primary px-3 py-2 font-mono text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
            </x-settings-field>

            <x-settings-field :label="__('opes.whatsapp.business_account_id')"
                              :hint="__('opes.whatsapp.business_account_id_hint')"
                              :error="$errors->first('businessAccountId')">
                <input type="text" wire:model="businessAccountId" inputmode="numeric" spellcheck="false"
                       class="w-full rounded-lg border border-border-primary px-3 py-2 font-mono text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
            </x-settings-field>

            <x-settings-field :label="__('opes.whatsapp.enabled')"
                              :hint="__('opes.whatsapp.enabled_hint')"
                              :error="$errors->first('enabled')">
                <label class="flex items-center gap-2 text-sm text-charcoal">
                    <input type="checkbox" wire:model="enabled"
                           class="h-4 w-4 rounded border-border-primary text-primary focus:ring-primary">
                    <span>{{ __('opes.whatsapp.enabled_label') }}</span>
                </label>
            </x-settings-field>
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.whatsapp.template')"
                             :hint="__('opes.whatsapp.template_hint')">

            <x-settings-field :label="__('opes.whatsapp.default_template')"
                              :hint="__('opes.whatsapp.default_template_hint')"
                              :error="$errors->first('defaultTemplate')">
                <input type="text" wire:model="defaultTemplate" spellcheck="false"
                       class="w-full rounded-lg border border-border-primary px-3 py-2 font-mono text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
            </x-settings-field>

            <x-settings-field :label="__('opes.whatsapp.default_template_language')"
                              :hint="__('opes.whatsapp.default_template_language_hint')"
                              :error="$errors->first('defaultTemplateLanguage')">
                <input type="text" wire:model="defaultTemplateLanguage" spellcheck="false" maxlength="16"
                       class="w-full rounded-lg border border-border-primary px-3 py-2 font-mono text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
            </x-settings-field>
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.whatsapp.test')"
                             :hint="__('opes.whatsapp.test_hint')">
            <x-settings-field :label="__('opes.whatsapp.test_recipient')"
                              :hint="__('opes.whatsapp.test_recipient_hint')"
                              :error="$errors->first('testRecipient')">
                <div class="flex items-start gap-2">
                    <input type="text" wire:model="testRecipient" spellcheck="false"
                           class="w-full rounded-lg border border-border-primary px-3 py-2 font-mono text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <button type="button" wire:click="sendTest" wire:loading.attr="disabled"
                            class="shrink-0 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 disabled:opacity-50">
                        {{ __('opes.whatsapp.send_test') }}
                    </button>
                </div>
            </x-settings-field>
        </x-settings-fieldset>
    </x-settings-form>

    {{-- Outside the form: this is evidence, not a setting. --}}
    <section class="mt-8">
        <h2 class="text-lg font-semibold text-charcoal">{{ __('opes.whatsapp.deliveries') }}</h2>
        <p class="mt-1 text-sm text-text-secondary">{{ __('opes.whatsapp.deliveries_hint') }}</p>

        @if ($deliveries === [])
            <p class="mt-4 rounded-xl border border-border-primary bg-sand px-4 py-6 text-center text-sm text-text-secondary">
                {{ __('opes.whatsapp.deliveries_empty') }}
            </p>
        @else
            <div class="mt-4 overflow-x-auto rounded-xl border border-border-primary bg-white">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="bg-sand text-xs font-semibold uppercase tracking-wide text-text-secondary">
                            <th class="px-3 py-2">{{ __('opes.whatsapp.col_when') }}</th>
                            <th class="px-3 py-2">{{ __('opes.whatsapp.col_recipient') }}</th>
                            <th class="px-3 py-2">{{ __('opes.whatsapp.col_type') }}</th>
                            <th class="px-3 py-2">{{ __('opes.whatsapp.col_status') }}</th>
                            <th class="px-3 py-2">{{ __('opes.whatsapp.col_detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $row)
                            @php($log = $row['log'])
                            <tr class="border-t border-border-primary align-top">
                                <td class="whitespace-nowrap px-3 py-2 text-text-secondary">
                                    {{ optional($log->created_at)->format('Y-m-d H:i') }}
                                </td>
                                <td class="px-3 py-2">
                                    {{-- Masked: a delivery list is read over
                                         somebody's shoulder in a busy office,
                                         and the last four digits are enough
                                         to confirm which parent this was. --}}
                                    <span class="font-mono text-xs">{{ $log->maskedPhone() }}</span>
                                    @if ($row['guardian'] !== null)
                                        <span class="block text-xs text-text-secondary">{{ $row['guardian'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ $log->message_type->value }}
                                    @if ($log->template_name !== null)
                                        <span class="block font-mono text-text-secondary">{{ $log->template_name }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @php($tone = match ($log->status->value) {
                                        'sent' => 'bg-success text-white',
                                        'failed' => 'bg-danger text-white',
                                        default => 'bg-heritage-yellow text-charcoal',
                                    })
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $tone }}">
                                        {{ __('opes.whatsapp.status_'.$log->status->value) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-xs text-text-secondary">
                                    @if ($log->error_message !== null)
                                        {{ $log->error_message }}
                                        @if ($log->error_code !== null)
                                            <span class="font-mono">({{ $log->error_code }})</span>
                                        @endif
                                    @elseif ($log->provider_message_id !== null)
                                        {{-- The only handle Meta support will
                                             accept when a school escalates. --}}
                                        <span class="font-mono break-all">{{ $log->provider_message_id }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
