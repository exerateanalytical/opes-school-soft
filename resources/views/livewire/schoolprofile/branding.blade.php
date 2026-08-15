{{-- /settings/branding - the school's palette, previewed on the real
     components it will repaint. A row of hex swatches cannot tell an
     operator that the primary they picked makes the table header
     unreadable; a live KPI card, table header, button and status pill can. --}}
<div>
    <x-settings-form :title="__('opes.branding.title')"
                     :description="__('opes.branding.subtitle')"
                     submit="save" cancel="cancel">

        <x-settings-fieldset :heading="__('opes.branding.presets')"
                             :hint="__('opes.branding.presets_hint')"
                             :columns="3">
            @foreach ($presets as $preset)
                <button type="button" wire:click="applyPreset('{{ $preset['key'] }}')"
                        class="flex items-center gap-3 rounded-lg border border-border-primary px-3 py-2 text-left text-sm transition hover:border-primary hover:bg-sand">
                    <span class="flex shrink-0 gap-1">
                        @foreach (['primary', 'secondary', 'accent'] as $swatch)
                            <span class="h-5 w-5 rounded-full border border-black/10"
                                  style="background: {{ $preset['colors'][$swatch] }}"></span>
                        @endforeach
                    </span>
                    <span class="font-medium text-charcoal">{{ $preset['label'] }}</span>
                </button>
            @endforeach
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.branding.colours')"
                             :hint="__('opes.branding.colours_hint')">
            @foreach ([
                'primary' => 'primary', 'secondary' => 'secondary', 'accent' => 'accent',
                'success' => 'success', 'warning' => 'warning', 'danger' => 'danger',
            ] as $model => $key)
                <x-settings-field :label="__('opes.branding.colour_'.$key)"
                                  :hint="__('opes.branding.colour_hint_'.$key)"
                                  :error="$errors->first($key)">
                    {{-- Picker and hex box are bound to the SAME property, so
                         they stay in sync by construction rather than by an
                         event handler that can be missed. `.live` on both:
                         the preview and the contrast warning are the point,
                         and they are useless a round trip behind. --}}
                    <span class="flex items-center gap-2">
                        <input type="color" wire:model.live="{{ $model }}"
                               aria-label="{{ __('opes.branding.colour_'.$key) }}"
                               class="h-10 w-12 shrink-0 cursor-pointer rounded border border-border-primary bg-white p-1">
                        <input type="text" wire:model.live.debounce.400ms="{{ $model }}"
                               spellcheck="false" maxlength="7"
                               class="w-full rounded-lg border border-border-primary px-3 py-2 font-mono text-sm uppercase text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </span>
                </x-settings-field>
            @endforeach
        </x-settings-fieldset>

        @if ($warnings !== [])
            <div role="alert" class="rounded-xl border border-warning/40 bg-warning-bg px-4 py-3 text-sm text-charcoal">
                <p class="font-semibold">{{ __('opes.branding.contrast_warning_title') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($warnings as $warning)
                        <li>
                            {{ __('opes.branding.contrast_warning_item', [
                                'colour' => __('opes.branding.colour_'.$warning['token']),
                                'ratio' => number_format($warning['ratio'], 2),
                            ]) }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs">{{ __('opes.branding.contrast_warning_body') }}</p>
            </div>
        @endif

        <x-settings-fieldset :heading="__('opes.branding.preview')"
                             :hint="__('opes.branding.preview_hint')"
                             :columns="2">
            {{-- The preview repaints by overriding the same custom properties
                 the shell layout emits, scoped to this container. Inline
                 style, not a class: the values are runtime data and Tailwind
                 has no utility for "whatever hex the operator just typed". --}}
            <div class="sm:col-span-2 rounded-xl border border-border-primary bg-ivory p-4"
                 style="{{ $previewStyle }}">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl bg-white p-4 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-wide text-text-secondary">
                            {{ __('opes.branding.preview_kpi_label') }}
                        </p>
                        <p class="mt-1 text-2xl font-bold" style="color: var(--color-primary)">1 284</p>
                    </div>

                    <div class="overflow-hidden rounded-xl border border-border-primary bg-white shadow-sm">
                        <div class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white"
                             style="background: var(--color-chrome)">
                            {{ __('opes.branding.preview_table_header') }}
                        </div>
                        <div class="px-3 py-2 text-sm text-charcoal">{{ __('opes.branding.preview_table_row') }}</div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                              style="background: var(--color-primary)">
                            {{ __('opes.ui.save') }}
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium text-white"
                              style="background: var(--color-success)">
                            {{ __('opes.branding.preview_pill_paid') }}
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium text-white"
                              style="background: var(--color-danger)">
                            {{ __('opes.branding.preview_pill_overdue') }}
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium text-charcoal"
                              style="background: var(--color-heritage-yellow)">
                            {{ __('opes.branding.preview_pill_pending') }}
                        </span>
                    </div>

                    <div class="rounded-xl p-4 text-sm font-medium text-white" style="background: var(--color-chrome)">
                        {{ __('opes.branding.preview_sidebar') }}
                        <div class="mt-2 rounded-lg px-3 py-2" style="background: var(--color-chrome-light)">
                            {{ __('opes.branding.preview_sidebar_active') }}
                        </div>
                    </div>
                </div>
            </div>
        </x-settings-fieldset>
    </x-settings-form>
</div>
