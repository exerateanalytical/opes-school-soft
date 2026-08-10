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

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($categories as $category)
            <a href="{{ route($category['route']) }}" wire:navigate
               class="group rounded-lg border border-sand bg-white p-5 shadow-sm transition hover:border-primary/50 hover:shadow-md">
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
            <div class="col-span-full rounded-lg border border-dashed border-sand bg-white p-8 text-center text-sm text-charcoal/60">
                No report screens have shipped yet.
            </div>
        @endforelse
    </div>
</div>
