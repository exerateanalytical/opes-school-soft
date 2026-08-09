<div class="min-w-0 space-y-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.dashboard_title') }}</h1>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.guardian_portal.dashboard_greeting', ['name' => $guardianName]) }}</p>
    </div>

    @if ($children === [])
        <x-empty-state :message="__('opes.guardian_portal.no_children')"/>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($children as $child)
                <div wire:key="portal-child-{{ $child['id'] }}" class="rounded border border-sand bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-base font-semibold text-charcoal">{{ $child['name'] }}</p>
                            <p class="text-xs text-charcoal/60">
                                {{ __('opes.guardians_screen.relationship_'.$child['relationship']) }}
                                @if ($child['class'])
                                    <span aria-hidden="true"> &middot; </span>{{ $child['class'] }}
                                @endif
                            </p>
                            <p class="font-mono text-xs text-charcoal/50">{{ $child['matricule'] }}</p>
                        </div>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-chrome text-sm font-semibold uppercase text-white">
                            {{ mb_substr($child['name'], 0, 1) }}
                        </span>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <a href="{{ route('portal.children.profile', $child['id']) }}"
                           class="rounded border border-sand px-2.5 py-1 font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.guardian_portal.tab_profile') }}
                        </a>
                        @if ($child['can_results'])
                            <a href="{{ route('portal.children.results', $child['id']) }}"
                               class="rounded border border-sand px-2.5 py-1 font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                {{ __('opes.guardian_portal.tab_results') }}
                            </a>
                        @endif
                        @if ($child['can_fees'])
                            <a href="{{ route('portal.children.fees', $child['id']) }}"
                               class="rounded border border-sand px-2.5 py-1 font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                {{ __('opes.guardian_portal.tab_fees') }}
                            </a>
                        @endif
                        @if ($child['can_discipline'])
                            <a href="{{ route('portal.children.discipline', $child['id']) }}"
                               class="rounded border border-sand px-2.5 py-1 font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                {{ __('opes.guardian_portal.tab_discipline') }}
                            </a>
                        @endif
                        @if ($child['can_documents'])
                            <a href="{{ route('portal.children.documents', $child['id']) }}"
                               class="rounded border border-sand px-2.5 py-1 font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                {{ __('opes.guardian_portal.tab_documents') }}
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
