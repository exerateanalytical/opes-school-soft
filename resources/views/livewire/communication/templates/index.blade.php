{{-- Message templates (08-operations 11.1). Toggle-form pattern, same chrome
     as Welfare\Visitors. SINGLE ROOT ELEMENT. --}}

@php
    $tabs = [
        ['value' => 'all', 'label' => 'All', 'count' => $tabCounts['all']],
        ['value' => 'active', 'label' => 'Active', 'count' => $tabCounts['active']],
        ['value' => 'inactive', 'label' => 'Inactive', 'count' => $tabCounts['inactive']],
    ];

    $emailChosen = $formChannel === 'email';
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @if ($showForm)
        <section aria-label="Message template" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">
                {{ $editingId === null ? 'New Template' : 'Edit Template' }}
            </h2>

            <form wire:submit="save" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="tpl-code" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Code (case-sensitive)</span>
                        <input id="tpl-code" type="text" wire:model="formCode"
                               placeholder="e.g. FEE-REMINDER"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 font-mono text-sm text-charcoal focus:border-primary/50"/>
                        @error('formCode')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="tpl-channel" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Channel</span>
                        <select id="tpl-channel" wire:model.live="formChannel"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($channels as $channelCase)
                                <option value="{{ $channelCase->value }}">{{ $channelCase->label() }}</option>
                            @endforeach
                        </select>
                        @error('formChannel')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="tpl-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Name (English)</span>
                        <input id="tpl-name" type="text" wire:model="formName"
                               placeholder="e.g. Fee reminder"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('formName')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="tpl-name-fr" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Name (French)</span>
                        <input id="tpl-name-fr" type="text" wire:model="formNameFr"
                               placeholder="e.g. Rappel de frais"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('formNameFr')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    @if ($emailChosen)
                        <label for="tpl-subject-en" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Subject (English)</span>
                            <input id="tpl-subject-en" type="text" wire:model="formSubjectEn"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('formSubjectEn')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="tpl-subject-fr" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Subject (French)</span>
                            <input id="tpl-subject-fr" type="text" wire:model="formSubjectFr"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('formSubjectFr')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                    @endif

                    <label for="tpl-body-en" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Body (English)</span>
                        <textarea id="tpl-body-en" rows="4" wire:model="formBodyEn"
                                  placeholder="Dear {guardian_name}, {student_name} owes {amount_due} FCFA."
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                        @error('formBodyEn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="tpl-body-fr" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Body (French)</span>
                        <textarea id="tpl-body-fr" rows="4" wire:model="formBodyFr"
                                  placeholder="Cher {guardian_name}, {student_name} doit {amount_due} FCFA."
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                        @error('formBodyFr')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="tpl-variables" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">
                            Merge fields used, comma separated (every {placeholder} in the bodies must be listed)
                        </span>
                        <input id="tpl-variables" type="text" wire:model="formVariables"
                               placeholder="guardian_name, student_name, amount_due"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 font-mono text-sm text-charcoal focus:border-primary/50"/>
                        @error('formVariables')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="tpl-active" class="flex items-center gap-2 sm:col-span-2">
                        <input id="tpl-active" type="checkbox" wire:model="formActive"
                               class="rounded border-border-primary text-primary focus:ring-primary"/>
                        <span class="text-sm text-charcoal/80">Active (deactivated templates cannot be sent)</span>
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        {{ $editingId === null ? 'Create template' : 'Save changes' }}
                    </button>
                    <button type="button" wire:click="detectVariables"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
                        Detect merge fields
                    </button>
                    <button type="button" wire:click="toggleForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    <x-list-screen
        title="Message Templates"
        :breadcrumb="['Dashboard', 'Communication', 'Templates']"
        :paginator="$rows"
        empty-message="No templates yet. Create one to give fee reminders and notices a consistent, bilingual wording."
        rail-title="About Templates"
    >
        <x-slot:actions>
            <button type="button" wire:click="toggleForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showForm ? 'Hide form' : 'New template' }}
            </button>
            <button type="button" wire:click="exportExcel"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
                Excel
            </button>
        </x-slot:actions>

        <x-slot:kpis>
            <x-kpi-card label="Templates" :value="$kpis['total']" icon-bg="bg-primary">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h16v16H4z"/><path stroke-linecap="round" d="M4 9h16M9 9v11"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Active" :value="$kpis['active']" icon-bg="bg-badge-blue">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 6L9 17l-5-5"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Inactive" :value="$kpis['inactive']" icon-bg="bg-chrome">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M8 12h8"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Messages Sent From Templates" :value="$kpis['messages']" icon-bg="bg-badge-orange">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                </x-slot:icon>
            </x-kpi-card>
        </x-slot:kpis>

        <x-slot:filters>
            <label for="tpl-filter-channel" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Channel</span>
                <select id="tpl-filter-channel" wire:model.live="channel"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All channels</option>
                    @foreach ($channels as $channelCase)
                        <option value="{{ $channelCase->value }}">{{ $channelCase->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label for="tpl-filter-search" class="flex min-w-[12rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Search</span>
                <input id="tpl-filter-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Code or name..."
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>
        </x-slot:filters>

        <x-slot:tabs>
            @foreach ($tabs as $tabOption)
                <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                        @if ($tab === $tabOption['value']) aria-current="page" @endif
                        class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                            ? 'border-primary font-semibold text-primary'
                            : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                    {{ $tabOption['label'] }}
                    <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tabOption['count'] }}</span>
                </button>
            @endforeach
        </x-slot:tabs>

        <x-slot:head>
            <tr class="bg-chrome text-white">
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Code</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Channel</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Merge fields</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Used</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr wire:key="tpl-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
                <td class="px-4 py-2.5 font-mono text-sm text-charcoal">{{ $row->code }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">
                    {{ $row->name }}
                    <span class="block text-xs text-charcoal/50">{{ $row->name_fr }}</span>
                </td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->channel->label() }}</td>
                <td class="max-w-[16rem] truncate px-4 py-2.5 font-mono text-xs text-charcoal/70">
                    {{ implode(', ', $row->declaredVariables()) ?: '—' }}
                </td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $usage[$row->id] ?? 0 }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->is_active ? 'ok' : 'amber'"
                                   :label="$row->is_active ? 'Active' : 'Inactive'"/>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <button type="button" wire:click="edit({{ $row->id }})"
                            class="text-sm font-medium text-primary hover:underline">Edit</button>
                    <button type="button" wire:click="toggleActive({{ $row->id }})"
                            class="ml-3 text-sm font-medium text-primary hover:underline">
                        {{ $row->is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                </td>
            </tr>
        @endforeach

        <x-slot:cards>
            @foreach ($rows as $row)
                <article wire:key="tpl-card-{{ $tab }}-{{ $row->id }}" class="rounded border border-border-primary bg-white p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-mono text-sm font-medium text-charcoal">{{ $row->code }}</p>
                        <x-status-pill :status="$row->is_active ? 'ok' : 'amber'"
                                       :label="$row->is_active ? 'Active' : 'Inactive'"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->name }} · {{ $row->channel->label() }}</p>
                    <div class="mt-2 flex items-center gap-3">
                        <button type="button" wire:click="edit({{ $row->id }})"
                                class="text-sm font-medium text-primary hover:underline">Edit</button>
                        <button type="button" wire:click="toggleActive({{ $row->id }})"
                                class="text-sm font-medium text-primary hover:underline">
                            {{ $row->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </div>
                </article>
            @endforeach
        </x-slot:cards>

        <x-slot:rail>
            <div class="space-y-3 text-sm text-charcoal/70">
                <p>
                    A template carries BOTH languages. The language a message
                    goes out in is the recipient's, decided when the message is
                    queued - not when the template is written.
                </p>
                <p>
                    Merge fields are written <code class="font-mono text-xs">{like_this}</code>.
                    Every one used in a body must be declared, so a template is
                    proved renderable at save instead of failing at send.
                </p>
                <p>
                    Templates are never deleted - a template with sent history is
                    deactivated, so the outbox's history stays readable.
                </p>
            </div>
        </x-slot:rail>
    </x-list-screen>
</div>
