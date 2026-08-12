{{-- `/portal/children` - mobile/my-children.png. --}}
<div class="min-w-0 space-y-5">
    <div class="flex items-center gap-3 pt-2">
        <x-portal.icon name="users" tone="primary"/>
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.guardian_portal.children_index_title') }}</h1>
            <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.children_index_subtitle') }}</p>
        </div>
    </div>

    @if ($children === [])
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="users" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.no_children') }}</p>
            </div>
        </x-portal.card>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($children as $child)
                @php
                    // The capability codes this parent holds for THIS child,
                    // mapped to the tabs they open. Different per child, which
                    // is exactly what parents ring the office about.
                    $caps = collect($child['capabilities']);
                    $chips = collect([
                        ['results.report_card.view', __('opes.guardian_portal.tab_results'), 'portal.children.results'],
                        ['results.attendance_summary.view', __('opes.guardian_portal.tab_attendance'), 'portal.children.attendance'],
                        ['fees.payments_own.view', __('opes.guardian_portal.tab_fees'), 'portal.children.fees'],
                        ['discipline.list.view', __('opes.guardian_portal.tab_discipline'), 'portal.children.discipline'],
                        ['child.medical_emergency.view', __('opes.guardian_portal.tab_health'), 'portal.children.health'],
                        ['documents.school_issued.view', __('opes.guardian_portal.tab_documents'), 'portal.children.documents'],
                    ])->filter(fn (array $c): bool => $caps->contains($c[0]));
                @endphp

                <x-portal.card wire:key="ci-{{ $child['id'] }}" :padded="false">
                    <div class="flex items-center gap-4 p-4 sm:p-5">
                        <x-portal.avatar :name="$child['display_name']" size="xl" tone="green"
                                         :photo="route('portal.photo.child', $child['id'])"/>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="truncate text-lg font-bold text-charcoal">{{ $child['display_name'] }}</p>
                                <span class="rounded-full bg-portal-chip px-2.5 py-0.5 text-xs font-semibold text-portal-success">
                                    {{ __('opes.guardian_portal.status_active') }}
                                </span>
                            </div>

                            <p class="mt-0.5 truncate text-sm text-charcoal/70">
                                {{ collect([$child['class'], $child['matricule']])->filter()->join('  •  ') }}
                            </p>
                        </div>
                    </div>

                    @if ($chips->isNotEmpty())
                        <div class="flex flex-wrap gap-2 px-4 pb-4 sm:px-5">
                            @foreach ($chips as $chip)
                                <a href="{{ route($chip[2], $child['id']) }}"
                                   class="rounded-full border border-border-primary px-3 py-1.5 text-xs font-medium text-charcoal/70 hover:border-primary/40 hover:text-primary">
                                    {{ $chip[1] }}
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <div class="border-t border-border-secondary">
                        <x-portal.row :title="__('opes.guardian_portal.child_overview_title')"
                                      :subtitle="__('opes.guardian_portal.tab_profile')"
                                      icon="user" tone="primary"
                                      :href="route('portal.children.overview', $child['id'])"/>
                    </div>
                </x-portal.card>
            @endforeach
        </div>
    @endif
</div>
