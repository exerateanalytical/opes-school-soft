<div class="min-w-0 space-y-4">
    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.search_title') }}</h1>

    <div>
        <label for="portal-search" class="sr-only">{{ __('opes.guardian_portal.search_title') }}</label>
        <input id="portal-search" type="search" wire:model.live.debounce.300ms="query"
               placeholder="{{ __('opes.guardian_portal.search_placeholder') }}"
               class="w-full rounded border border-border-primary bg-white px-4 py-2.5 text-sm text-charcoal shadow-sm focus:border-primary focus:outline-none">
    </div>

    @if ($tooShort)
        <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.search_min_length') }}</p>
    @elseif ($term !== '')
        <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.search_results_for', ['query' => $term]) }}</p>

        @if ($results === [])
            <x-empty-state :message="__('opes.guardian_portal.search_empty')"/>
        @else
            <ul class="divide-y divide-border-secondary rounded border border-border-primary bg-white shadow-sm">
                @foreach ($results as $result)
                    @php
                        // The deep link is the app's `opes://` scheme; the web
                        // portal maps the same target onto its own routes.
                        $href = match ($result['type']) {
                            'child' => route('portal.children.profile', $result['student_id']),
                            'invoice' => route('portal.children.fees', $result['student_id']),
                            'receipt' => route('portal.payments'),
                            'document' => route('portal.children.documents', $result['student_id']),
                            'discipline' => route('portal.children.discipline', $result['student_id']),
                            'announcement' => route('portal.announcements'),
                            default => route('portal.dashboard'),
                        };
                    @endphp
                    <li wire:key="hit-{{ $result['type'] }}-{{ $result['id'] }}">
                        <a href="{{ $href }}" class="flex items-center gap-3 px-4 py-3 hover:bg-surface-secondary">
                            <span class="shrink-0 rounded bg-surface-green px-2 py-0.5 text-[11px] font-medium text-primary">
                                {{ __('opes.guardian_portal.search_type_'.$result['type']) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-charcoal">{{ $result['title'] }}</span>
                                @if ($result['subtitle'])
                                    <span class="block truncate text-xs text-charcoal/60">{{ $result['subtitle'] }}</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
