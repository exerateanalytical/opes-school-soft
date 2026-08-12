{{--
    `/portal` - built to mobile/parent-dashboard.png, in the design's own
    order: greeting and date chip, the children carousel, the overview tiles,
    the unread strip, the two half-width panels, quick actions, safety banner.
--}}
<div class="min-w-0 space-y-5">

    {{-- ------------------------------------------------- greeting + date -- --}}
    <div class="flex flex-wrap items-start justify-between gap-3 pt-2">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-charcoal">
                {{ __('opes.guardian_portal.dashboard_welcome', ['name' => $guardianName]) }}
            </h1>
            <p class="mt-1 text-sm text-charcoal/60">{{ __('opes.guardian_portal.dashboard_today') }}</p>
        </div>

        <div class="flex shrink-0 items-center gap-2.5 rounded-2xl border border-border-primary bg-white p-3 shadow-[0_2px_10px_rgba(0,45,23,0.06)]">
            <x-portal.icon name="calendar" tone="primary" size="sm"/>
            <span class="leading-tight">
                <span class="block text-[11px] text-charcoal/60">{{ now()->translatedFormat('l') }}</span>
                <span class="block text-sm font-bold text-charcoal">{{ now()->translatedFormat('j M Y') }}</span>
            </span>
        </div>
    </div>

    {{-- ------------------------------------------------------- children -- --}}
    <div class="space-y-3">
        <x-portal.section :title="__('opes.guardian_portal.dashboard_title')" icon="users"/>

        @if ($children === [])
            <x-portal.card>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.no_children') }}</p>
            </x-portal.card>
        @else
            <div class="-mx-4 flex snap-x gap-3 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0">
                @foreach ($children as $child)
                    <a wire:key="dash-child-{{ $child['id'] }}"
                       href="{{ route('portal.children.profile', $child['id']) }}"
                       @class([
                           'w-44 shrink-0 snap-start rounded-2xl border p-3 shadow-[0_2px_10px_rgba(0,45,23,0.06)]',
                           'border-primary bg-portal-selected' => $loop->first,
                           'border-border-primary bg-white' => ! $loop->first,
                       ])>
                        <x-portal.avatar :name="$child['name']" size="lg" tone="green"
                                         :photo="route('portal.photo.child', $child['id'])"/>

                        <p class="mt-2 truncate text-sm font-semibold text-charcoal">{{ $child['name'] }}</p>
                        <p class="truncate text-xs text-charcoal/60">{{ $child['class'] ?? $child['matricule'] }}</p>

                        <span class="mt-2 inline-block rounded-full bg-portal-chip px-2.5 py-0.5 text-[11px] font-semibold text-portal-success">
                            {{ __('opes.guardian_portal.status_active') }}
                        </span>
                    </a>
                @endforeach

                <a href="{{ route('portal.children.index') }}"
                   class="flex w-32 shrink-0 snap-start flex-col items-center justify-center gap-2 rounded-2xl border border-border-primary bg-portal-tint p-3 text-center">
                    <x-portal.icon name="users" tone="primary"/>
                    <span class="text-xs font-semibold text-primary">
                        {{ __('opes.guardian_portal.dashboard_view_all_children') }}
                    </span>
                </a>
            </div>
        @endif
    </div>

    {{-- ------------------------------------------------------- overview -- --}}
    @if ($tiles !== [])
        <div class="space-y-3">
            <x-portal.section :title="__('opes.guardian_portal.dashboard_overview')" icon="chart"/>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ($tiles as $tile)
                    <x-portal.card :padded="false" wire:key="tile-{{ $loop->index }}">
                        <x-portal.stat :label="$tile['label']" :value="$tile['value']"
                                       :caption="$tile['caption']"
                                       :icon="$tile['icon']" :tone="$tile['tone']"/>
                    </x-portal.card>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ------------------------------------------ unread messages strip -- --}}
    @if ($unreadMessages > 0)
        <x-portal.card tone="gold" class="flex flex-wrap items-center gap-3">
            <x-portal.icon name="bell" tone="gold"/>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-charcoal">
                    {{ __('opes.guardian_portal.dashboard_unread', ['count' => $unreadMessages]) }}
                </p>
                <p class="text-xs text-charcoal/60">{{ __('opes.guardian_portal.dashboard_check_inbox') }}</p>
            </div>

            <a href="{{ route('portal.messages') }}"
               class="shrink-0 rounded-xl bg-white px-3 py-2 text-xs font-semibold text-primary shadow-sm">
                {{ __('opes.guardian_portal.dashboard_view_messages') }}
            </a>
        </x-portal.card>
    @endif

    {{-- ------------------------------------------- messages + activities -- --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.dashboard_recent_messages')" icon="chat"
                                  :action="__('opes.guardian_portal.view_all')"
                                  :href="route('portal.messages')"/>
            </div>

            @if ($recentMessages === [])
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.messages_empty') }}</p>
            @else
                <div class="divide-y divide-border-secondary pb-1">
                    @foreach ($recentMessages as $message)
                        <x-portal.row wire:key="dash-msg-{{ $message['id'] }}"
                                      :title="$message['title']"
                                      :subtitle="$message['subtitle']"
                                      icon="mail" tone="primary"
                                      :unread="$message['unread']"
                                      :href="route('portal.messages.thread', $message['id'])"/>
                    @endforeach
                </div>
            @endif
        </x-portal.card>

        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.dashboard_upcoming')" icon="calendar"
                                  :action="__('opes.guardian_portal.view_all')"
                                  :href="route('portal.announcements')"/>
            </div>

            @if ($upcoming === [])
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.announcements_empty') }}</p>
            @else
                <div class="divide-y divide-border-secondary pb-1">
                    @foreach ($upcoming as $event)
                        <div wire:key="dash-evt-{{ $loop->index }}" class="flex items-center gap-3 px-4 py-3">
                            <span class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-surface-secondary">
                                <span class="text-[10px] font-semibold uppercase text-charcoal/50">{{ $event['month'] }}</span>
                                <span class="text-base font-bold leading-none text-charcoal">{{ $event['day'] }}</span>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-charcoal">{{ $event['title'] }}</span>
                                <span class="block truncate text-xs text-charcoal/60">{{ $event['when'] }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-portal.card>
    </div>

    {{-- -------------------------------------------------- quick actions -- --}}
    <div class="space-y-3">
        <x-portal.section :title="__('opes.guardian_portal.dashboard_quick_actions')" icon="gear"/>

        <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
            @foreach ($quickActions as $action)
                <a wire:key="qa-{{ $loop->index }}" href="{{ $action['href'] }}"
                   class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-border-primary bg-white px-2 py-4 text-center shadow-[0_2px_10px_rgba(0,45,23,0.06)] hover:border-primary/40">
                    <x-portal.icon :name="$action['icon']" tone="primary" size="sm"/>
                    <span class="text-[11px] font-medium leading-tight text-charcoal">{{ $action['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- ---------------------------------------------------- safety band -- --}}
    <x-portal.card tone="chrome">
        <div class="flex flex-wrap items-center gap-4">
            <x-portal.icon name="shield" tone="onChrome" size="lg"/>

            <div class="min-w-0 flex-1">
                <p class="text-base font-bold text-white">{{ __('opes.guardian_portal.dashboard_safety_title') }}</p>
                <p class="mt-1 text-sm text-white/70">{{ __('opes.guardian_portal.dashboard_safety_body') }}</p>
            </div>

            <a href="{{ route('portal.account.edit') }}"
               class="shrink-0 rounded-xl bg-portal-gold px-4 py-2.5 text-sm font-bold text-charcoal hover:brightness-105">
                {{ __('opes.guardian_portal.dashboard_update_profile') }}
            </a>
        </div>
    </x-portal.card>
</div>
