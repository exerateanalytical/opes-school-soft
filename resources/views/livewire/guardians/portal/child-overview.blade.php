{{-- `/portal/children/{s}/overview` - mobile/child-overview.png. --}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'profile',
    ])

    @if ($tiles !== [])
        <x-portal.card :padded="false">
            <div class="grid grid-cols-2 divide-x divide-y divide-border-secondary sm:grid-cols-3 sm:divide-y-0">
                @foreach ($tiles as $tile)
                    <x-portal.stat wire:key="co-tile-{{ $loop->index }}"
                                   :label="$tile['label']" :value="$tile['value']"
                                   :icon="$tile['icon']" :tone="$tile['tone']"/>
                @endforeach
            </div>
        </x-portal.card>
    @endif

    {{-- Only what this child's link actually opens. A tile that answered 403
         on tap is the complaint this restyle started from. --}}
    <div class="space-y-3">
        <x-portal.section :title="__('opes.guardian_portal.child_overview_title')" icon="users"/>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($links as $link)
                <a wire:key="co-link-{{ $loop->index }}" href="{{ route($link[1], $studentId) }}"
                   class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-border-primary bg-white px-2 py-5 text-center shadow-[0_2px_10px_rgba(0,45,23,0.06)] hover:border-primary/40">
                    <x-portal.icon :name="$link[3]" tone="primary"/>
                    <span class="text-xs font-medium leading-tight text-charcoal">{{ $link[2] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
