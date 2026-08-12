{{-- `/portal/search` - built to mobile/global-search.png. --}}
<div class="min-w-0 space-y-5">

    <div class="flex items-center gap-3 pt-2">
        <x-portal.icon name="search" tone="primary"/>
        <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.guardian_portal.search_title') }}</h1>
    </div>

    <div class="relative">
        <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-charcoal/40">
            <x-portal.icon name="search" bare size="md"/>
        </span>

        <label for="portal-search" class="sr-only">{{ __('opes.guardian_portal.search_title') }}</label>
        <input id="portal-search" type="search" wire:model.live.debounce.300ms="query"
               placeholder="{{ __('opes.guardian_portal.search_placeholder') }}"
               class="w-full rounded-2xl border border-border-primary bg-white py-3.5 pl-12 pr-4 text-sm text-charcoal shadow-[0_2px_10px_rgba(0,45,23,0.06)] focus:border-primary focus:outline-none">
    </div>

    @if ($tooShort)
        <p class="px-1 text-sm text-charcoal/60">{{ __('opes.guardian_portal.search_min_length') }}</p>
    @elseif ($term !== '')
        <p class="px-1 text-sm text-charcoal/60">
            {{ __('opes.guardian_portal.search_results_for', ['query' => $term]) }}
        </p>

        @if ($results === [])
            <x-portal.card>
                <div class="flex flex-col items-center gap-3 py-6 text-center">
                    <x-portal.icon name="search" tone="primary" size="lg"/>
                    <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.search_empty') }}</p>
                </div>
            </x-portal.card>
        @else
            <x-portal.card :padded="false">
                <div class="divide-y divide-border-secondary">
                    @foreach ($results as $result)
                        @php
                            // The `opes://` deep link is the app's; the portal
                            // maps the same target onto its own routes.
                            [$icon, $href] = match ($result['type']) {
                                'child' => ['users', route('portal.children.profile', $result['student_id'])],
                                'invoice' => ['card', route('portal.children.invoice', [$result['student_id'], $result['id']])],
                                'receipt' => ['receipt', route('portal.children.receipt', [$result['student_id'], $result['id']])],
                                'document' => ['file', route('portal.children.documents', $result['student_id'])],
                                'discipline' => ['alert', route('portal.children.discipline', $result['student_id'])],
                                'announcement' => ['megaphone', route('portal.announcements')],
                                default => ['dot', route('portal.dashboard')],
                            };
                        @endphp

                        <x-portal.row wire:key="hit-{{ $result['type'] }}-{{ $result['id'] }}"
                                      :title="$result['title']"
                                      :subtitle="$result['subtitle']"
                                      :icon="$icon" tone="primary"
                                      :trailing="__('opes.guardian_portal.search_type_'.$result['type'])"
                                      trailingTone="primary"
                                      :href="$href"/>
                    @endforeach
                </div>
            </x-portal.card>
        @endif
    @endif
</div>
