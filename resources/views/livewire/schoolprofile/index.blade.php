@php
    use App\Modules\Identity\Domain\Permission;
    use App\Modules\SchoolProfile\Domain\SettingType;
    use Illuminate\Support\Facades\Gate;

    $canEdit = Gate::check(Permission::SettingEdit);

    /**
     * setting_class -> pill tone for the class badge shown per row. The
     * WORD carries the meaning (09-ui 10); the colour only reinforces it.
     */
    $classTone = [
        'cosmetic' => 'ok',
        'operational' => 'amber',
        'engine_behaviour' => 'red',
    ];

    $classLabel = [
        'cosmetic' => 'Cosmetic',
        'operational' => 'Operational',
        'engine_behaviour' => 'Engine behaviour',
    ];

    /**
     * Renders a stored JSON value per its declared value_type, kept simple
     * for this read-only pass: scalars print directly, json_encode for
     * anything complex.
     */
    $renderValue = function (?string $raw, string $type): string {
        if ($raw === null) {
            return '—';
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $raw;
        }

        return match ($type) {
            SettingType::Bool->value => $decoded ? 'True' : 'False',
            SettingType::String->value, SettingType::Int->value => (string) $decoded,
            default => json_encode($decoded, JSON_UNESCAPED_SLASHES),
        };
    };
@endphp

{{-- One root element: Livewire 4 refuses a multi-root component, and the hub
     section below made this view's flash + list-screen pair multi-root. --}}
<div class="min-w-0">
@if (session('status'))
    <p class="mb-4 rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
        {{ session('status') }}
    </p>
@endif

{{-- The hub: /settings used to render a raw key/value table with zero links
     while six real settings screens sat unreachable beside it. The cards are
     permission-filtered in hubCards() so no role is offered a link its
     permissions refuse (the nav-and-route-agree contract). --}}
@if ($hubCards !== [])
    <section class="mb-6">
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
            {{ __('opes.settings_hub.heading') }}
        </h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($hubCards as $card)
                <a href="{{ $card['href'] }}"
                   class="block rounded-xl border border-kpi-green-solid/15 bg-kpi-green p-4 transition hover:-translate-y-px hover:shadow-md">
                    <p class="text-sm font-semibold text-charcoal">{{ $card['title'] }}</p>
                    <p class="mt-1 text-xs text-charcoal/60">{{ $card['body'] }}</p>
                </a>
            @endforeach
        </div>
    </section>
@endif

<x-list-screen
    title="Settings"
    :breadcrumb="['Dashboard', 'Settings']"
    :paginator="$rows"
    empty-message="No settings match these filters yet."
>
    {{-- KPI strip: total settings, locked settings, settings still at
         their seeded default value - all dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Total Settings" :value="$kpis['total']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Locked Settings" :value="$kpis['locked']" icon-bg="bg-heritage-red">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path stroke-linecap="round" d="M8 10V7a4 4 0 018 0v3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="At Default Value" :value="$kpis['at_default']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path stroke-linecap="round" d="M12 8v4l2.5 2.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="settings-filter-class" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Class</span>
            <select id="settings-filter-class" wire:model.live="settingClass"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All classes</option>
                @foreach ($classOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="settings-filter-scope" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Scope</span>
            <select id="settings-filter-scope" wire:model.live="scope"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All scopes</option>
                @foreach ($scopeOptions as $option)
                    <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </label>

        <label for="settings-filter-locked" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Lock status</span>
            <select id="settings-filter-locked" wire:model.live="locked"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All settings</option>
                <option value="locked">Locked</option>
                <option value="unlocked">Unlocked</option>
            </select>
        </label>

        <label for="settings-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="settings-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search key..."
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Key</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Value</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Scope</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Lock Status</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Last Updated</th>
            @if ($canEdit)
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Actions</span></th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="setting-{{ $row->id }}" class="hover:bg-sand/30">
            <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->key }}</td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$classTone[$row->setting_class] ?? 'ok'" :label="$classLabel[$row->setting_class] ?? $row->setting_class"/>
            </td>
            <td class="px-4 py-2.5 text-charcoal/80">
                @if ($editingSettingId === $row->id)
                    <form wire:submit="saveEditedSetting" class="flex flex-col gap-1.5">
                        @if ($row->value_type === SettingType::Bool->value)
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="editValueBool" class="rounded border-border-primary"/>
                                <span>{{ $editValueBool ? 'True' : 'False' }}</span>
                            </label>
                        @elseif ($row->value_type === SettingType::Int->value)
                            <input type="number" wire:model="editValue"
                                   class="w-32 rounded border border-border-primary bg-white px-2 py-1 text-sm text-charcoal"/>
                        @elseif ($row->value_type === SettingType::Json->value)
                            <textarea wire:model="editValue" rows="3"
                                      class="w-64 rounded border border-border-primary bg-white px-2 py-1 font-mono text-xs text-charcoal"></textarea>
                        @else
                            <input type="text" wire:model="editValue"
                                   class="w-56 rounded border border-border-primary bg-white px-2 py-1 text-sm text-charcoal"/>
                        @endif
                        @error('editValue')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                        <div class="flex items-center gap-2">
                            <button type="submit" wire:confirm="This changes engine configuration. Save this setting?"
                                    class="rounded bg-primary px-2.5 py-1 text-xs font-semibold text-white hover:bg-primary/90">
                                Save
                            </button>
                            <button type="button" wire:click="cancelEdit"
                                    class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-charcoal/70 hover:text-charcoal">
                                Cancel
                            </button>
                        </div>
                    </form>
                @else
                    <code class="text-xs">{{ $renderValue($row->value, $row->value_type) }}</code>
                @endif
            </td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ ucfirst($row->scope) }}{{ $row->scope_id !== null ? ' #'.$row->scope_id : '' }}</td>
            <td class="px-4 py-2.5">
                @if ($row->locked_at !== null)
                    <span title="{{ $row->locked_reason ?? 'No reason recorded.' }}">
                        <x-status-pill status="red" label="Locked"/>
                    </span>
                @else
                    <x-status-pill status="ok" label="Unlocked"/>
                @endif
            </td>
            <td class="px-4 py-2.5 text-charcoal/80">
                {{ $row->updated_at !== null ? \Illuminate\Support\Carbon::parse($row->updated_at)->format('Y-m-d H:i') : '—' }}
                @if ($row->updated_by_name !== null)
                    <span class="block text-xs text-charcoal/50">by {{ $row->updated_by_name }}</span>
                @endif
            </td>
            @if ($canEdit)
                <td class="px-4 py-2.5 text-right">
                    @if ($row->locked_at === null && $editingSettingId !== $row->id)
                        <button type="button" wire:click="startEdit({{ $row->id }})"
                                class="text-sm font-medium text-primary hover:underline">
                            Edit
                        </button>
                    @endif
                </td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: key, class, value and lock status. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="setting-card-{{ $row->id }}" class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-center justify-between gap-2">
                    <p class="font-medium text-charcoal">{{ $row->key }}</p>
                    @if ($row->locked_at !== null)
                        <span title="{{ $row->locked_reason ?? 'No reason recorded.' }}">
                            <x-status-pill status="red" label="Locked"/>
                        </span>
                    @else
                        <x-status-pill status="ok" label="Unlocked"/>
                    @endif
                </div>
                <p class="mt-1 text-sm text-charcoal/70">
                    {{ $classLabel[$row->setting_class] ?? $row->setting_class }} ·
                    <code class="text-xs">{{ $renderValue($row->value, $row->value_type) }}</code>
                </p>
                <p class="mt-1 text-xs text-charcoal/50">{{ ucfirst($row->scope) }}{{ $row->scope_id !== null ? ' #'.$row->scope_id : '' }}</p>
            </article>
        @endforeach
    </x-slot:cards>

    {{-- Right rail: settings-by-class breakdown. --}}
    <x-slot:rail>
        <div class="space-y-4">
            <section aria-label="Settings by class" class="rounded border border-border-primary bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Settings by Class</h3>
                <ul class="space-y-2.5">
                    @foreach ($classOptions as $option)
                        <li class="flex items-center justify-between text-sm text-charcoal/70">
                            <span>{{ $option['label'] }}</span>
                            <span class="tabular-nums font-medium text-charcoal">{{ $classCounts[$option['value']] ?? 0 }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </x-slot:rail>
</x-list-screen>
</div>
