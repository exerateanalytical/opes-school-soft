<div class="min-w-0 space-y-4">
    <div>
        <nav class="mb-1 text-xs text-charcoal/60">
            <a href="{{ route('dashboard') }}" wire:navigate>Dashboard</a>
            <span class="mx-1">/</span>
            <span>Reports</span>
        </nav>
        <h1 class="text-xl font-semibold text-charcoal">Reports</h1>
        <p class="mt-1 text-sm text-charcoal/70">
            Every report in the system, grouped by area. Each report can be previewed on screen and exported to
            Excel or PDF, or printed directly.
        </p>
    </div>

    {{-- The reference's headline strip. Its fifth tile is a "Pass Rate
         (Overall)" and is deliberately absent: a pass mark is per assessment
         framework and nothing has been published to average, so the figure
         would be invented - on the reports screen of all places.

         Every tile is permission-gated inside the component and returns null
         rather than 0 for a reader who may not see it, so a reports viewer
         without students.view gets three tiles rather than a lie about a roll
         of nobody. --}}
    @php
        $headlineTiles = [
            ['key' => 'students', 'tone' => 'bg-primary', 'icon' => 'students'],
            ['key' => 'staff', 'tone' => 'bg-badge-blue', 'icon' => 'staff'],
            ['key' => 'classes', 'tone' => 'bg-badge-orange', 'icon' => 'classes'],
            ['key' => 'examinations', 'tone' => 'bg-badge-purple', 'icon' => 'examinations'],
        ];

        $visibleTiles = array_values(array_filter(
            $headlineTiles,
            static fn (array $tile): bool => ($headlineFigures[$tile['key']] ?? null) !== null,
        ));
    @endphp

    @if ($visibleTiles !== [])
        <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(185px,1fr))]">
            @foreach ($visibleTiles as $tile)
                <x-kpi-card :label="__('opes.reports_hub.kpi_'.$tile['key'])"
                            :value="number_format($headlineFigures[$tile['key']])"
                            :sub="__('opes.reports_hub.kpi_'.$tile['key'].'_sub')"
                            :icon-bg="$tile['tone']">
                    <x-slot:icon>
                        <x-opes-nav-icon :nav-key="$tile['icon']" class="h-5 w-5"/>
                    </x-slot:icon>
                </x-kpi-card>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($categories as $category)
            <a href="{{ route($category['route']) }}" wire:navigate
               class="group rounded-lg border border-border-primary bg-white p-5 shadow-sm transition hover:border-primary/50 hover:shadow-md">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <x-opes-nav-icon :nav-key="$category['icon']" class="h-5 w-5"/>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-semibold text-charcoal group-hover:text-primary">{{ $category['category'] }}</h2>
                        <p class="mt-1 text-xs text-charcoal/60">{{ $category['description'] }}</p>
                    </div>
                </div>
            </a>
        @empty
            <div class="col-span-full rounded-lg border border-dashed border-border-primary bg-white p-8 text-center text-sm text-charcoal/60">
                No report screens have shipped yet.
            </div>
        @endforelse
    </div>
</div>
