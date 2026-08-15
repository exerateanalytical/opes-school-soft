@php
    $isPublished = $curriculum->isPublished();
@endphp

<div class="min-w-0 space-y-4">
    {{-- Breadcrumb --}}
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li><a href="{{ route('curriculum.index') }}" class="hover:text-primary hover:underline">Curriculum</a></li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $header->title ?? '' }}</li>
        </ol>
    </nav>

    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    {{-- Title + identity facts --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-charcoal">{{ $header->title ?? '' }}</h1>
            <p class="mt-1 text-sm text-charcoal/70">
                {{ $header->subject_code ?? '' }} - {{ $header->subject_name ?? '' }}
                · {{ $header->level_name ?? '' }}
                · {{ ucfirst($header->sub_system ?? '') }}
                · {{ $header->year_code ?? '' }}
            </p>
            @if (($header->description ?? null) !== null)
                <p class="mt-1 max-w-prose text-sm text-charcoal/60">{{ $header->description }}</p>
            @endif
        </div>
    </div>

    {{-- ── Version banner: draft vs published, Publish / Revise ────────── --}}
    <section aria-label="Version"
             class="rounded-xl border p-4 {{ $isPublished ? 'border-primary/30 bg-primary/5' : 'border-heritage-yellow/60 bg-heritage-yellow/10' }}">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <x-status-pill :status="$isPublished ? 'ok' : 'amber'" :label="$isPublished ? 'Published' : 'Draft'"/>
                <p class="text-sm font-semibold text-charcoal">Version {{ $curriculum->version }}</p>

                @if ($isPublished)
                    <p class="text-sm text-charcoal/70">
                        Published {{ $curriculum->published_at?->format('d M Y H:i') }}
                        @if (($header->published_by_name ?? null) !== null)
                            by {{ $header->published_by_name }}
                        @endif
                        - this version is locked; a change is a new version.
                    </p>
                @else
                    <p class="text-sm text-charcoal/70">
                        Draft - editable until published. Publishing locks this version permanently.
                    </p>
                @endif
            </div>

            <div class="flex items-center gap-2">
                {{-- Other versions of the same identity. --}}
                @if (count($versions) > 1)
                    <nav aria-label="Versions" class="flex items-center gap-1">
                        @foreach ($versions as $version)
                            @if ($version['id'] === $curriculum->id)
                                <span aria-current="page"
                                      class="rounded border border-primary bg-primary px-2 py-1 text-xs font-semibold text-white">
                                    v{{ $version['version'] }}
                                </span>
                            @else
                                <a href="{{ route('curriculum.show', ['curriculum' => $version['id']]) }}"
                                   class="rounded border border-border-primary px-2 py-1 text-xs font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                                    v{{ $version['version'] }}
                                </a>
                            @endif
                        @endforeach
                    </nav>
                @endif

                @if ($canManage && ! $isPublished)
                    <button type="button" wire:click="publish"
                            wire:confirm="Publish this version? It becomes permanently locked; further changes will require a new version."
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Publish
                    </button>
                @endif

                @if ($canManage && $isPublished)
                    <button type="button" wire:click="revise"
                            class="rounded border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/10">
                        Revise (new version)
                    </button>
                @endif
            </div>
        </div>
    </section>

    {{-- ── Tabs ─────────────────────────────────────────────────────────── --}}
    <div class="-mx-4 overflow-x-auto border-b border-border-primary px-4 sm:mx-0 sm:px-0">
        <div class="flex min-w-max items-center gap-1">
            <button type="button" wire:click="selectTab('structure')"
                    class="border-b-2 px-3 py-2 text-sm font-medium {{ $tab === 'structure' ? 'border-primary text-primary' : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                Units &amp; Topics ({{ $curriculum->units->count() }})
            </button>
            <button type="button" wire:click="selectTab('competencies')"
                    class="border-b-2 px-3 py-2 text-sm font-medium {{ $tab === 'competencies' ? 'border-primary text-primary' : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                Competencies ({{ $curriculum->competencies->count() }})
            </button>
        </div>
    </div>

    @if ($tab === 'structure')
        {{-- ── Units / topics tree ──────────────────────────────────────── --}}
        @if ($canManage && ! $isPublished)
            <div class="flex justify-end">
                <button type="button" wire:click="toggleUnitForm"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    {{ $showUnitForm ? 'Hide form' : 'Add unit' }}
                </button>
            </div>
        @endif

        @if ($showUnitForm)
            <section aria-label="Add unit" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
                <form wire:submit="saveUnit" class="space-y-3">
                    <label for="unit-form-title" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Unit title</span>
                        <input id="unit-form-title" type="text" wire:model="unitFormTitle"
                               placeholder="e.g. Unit 1 - Numbers and Operations"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('unitFormTitle')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                    <label for="unit-form-description" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Description (optional)</span>
                        <textarea id="unit-form-description" wire:model="unitFormDescription" rows="2"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    </label>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">Save unit</button>
                        <button type="button" wire:click="toggleUnitForm" class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </section>
        @endif

        @if ($curriculum->units->isEmpty())
            <x-empty-state title="No units yet"
                           message="A curriculum is built as ordered units, each holding ordered topics with their intended learning outcomes. Add the first unit to begin the structure.">
                @if ($canManage && ! $isPublished)
                    <x-slot:action>
                        <button type="button" wire:click="toggleUnitForm"
                                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                            Add unit
                        </button>
                    </x-slot:action>
                @endif
            </x-empty-state>
        @else
            <ol class="space-y-3">
                @foreach ($curriculum->units as $unit)
                    <li wire:key="unit-{{ $unit->id }}" class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h2 class="text-base font-semibold text-charcoal">
                                    <span class="mr-1 text-charcoal/50">{{ $unit->sequence }}.</span>{{ $unit->title }}
                                </h2>
                                @if ($unit->description !== null)
                                    <p class="mt-0.5 max-w-prose text-sm text-charcoal/60">{{ $unit->description }}</p>
                                @endif
                            </div>
                            @if ($canManage && ! $isPublished)
                                <button type="button" wire:click="openTopicForm({{ $unit->id }})"
                                        class="rounded border border-primary px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/10">
                                    {{ $topicFormUnitId === $unit->id ? 'Hide form' : 'Add topic' }}
                                </button>
                            @endif
                        </div>

                        @if ($topicFormUnitId === $unit->id)
                            <form wire:submit="saveTopic" class="mt-3 space-y-3 rounded-lg border border-border-primary bg-sand/40 p-3">
                                <label for="topic-form-title-{{ $unit->id }}" class="flex flex-col gap-1">
                                    <span class="text-xs font-medium text-charcoal/70">Topic title</span>
                                    <input id="topic-form-title-{{ $unit->id }}" type="text" wire:model="topicFormTitle"
                                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                    @error('topicFormTitle')
                                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                                    @enderror
                                </label>
                                <label for="topic-form-outcome-{{ $unit->id }}" class="flex flex-col gap-1">
                                    <span class="text-xs font-medium text-charcoal/70">Intended learning outcome (optional)</span>
                                    <textarea id="topic-form-outcome-{{ $unit->id }}" wire:model="topicFormOutcome" rows="2"
                                              placeholder="What should the learner be able to do after this topic?"
                                              class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                                </label>
                                <div class="flex items-center gap-3">
                                    <button type="submit" class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">Save topic</button>
                                    <button type="button" wire:click="openTopicForm({{ $unit->id }})" class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:text-charcoal">Cancel</button>
                                </div>
                            </form>
                        @endif

                        @if ($unit->topics->isEmpty())
                            <p class="mt-3 rounded border border-dashed border-border-primary px-3 py-2 text-sm text-charcoal/50">
                                No topics in this unit yet.
                            </p>
                        @else
                            {{-- Topics, indented under their unit. --}}
                            <ol class="mt-3 space-y-2 border-l-2 border-border-primary pl-4">
                                @foreach ($unit->topics as $topic)
                                    <li wire:key="topic-{{ $topic->id }}" class="rounded-lg bg-sand/40 p-3">
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-charcoal">
                                                    <span class="mr-1 text-charcoal/50">{{ $unit->sequence }}.{{ $topic->sequence }}</span>{{ $topic->title }}
                                                </p>
                                                @if ($topic->learning_outcome !== null)
                                                    <p class="mt-0.5 max-w-prose text-xs text-charcoal/60">
                                                        <span class="font-semibold uppercase tracking-wide text-charcoal/45">Outcome:</span>
                                                        {{ $topic->learning_outcome }}
                                                    </p>
                                                @endif
                                                @if ($topic->competencies->isNotEmpty())
                                                    <p class="mt-1.5 flex flex-wrap items-center gap-1">
                                                        @foreach ($topic->competencies as $competency)
                                                            <span class="rounded-full border border-primary/30 bg-primary/5 px-2 py-0.5 text-xs font-medium text-primary"
                                                                  title="{{ $competency->descriptor }}">
                                                                {{ $competency->code }}
                                                            </span>
                                                        @endforeach
                                                    </p>
                                                @endif
                                            </div>
                                            @if ($canManage && ! $isPublished)
                                                <button type="button" wire:click="openLinkForm({{ $topic->id }})"
                                                        class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                                                    {{ $linkFormTopicId === $topic->id ? 'Hide' : 'Link competency' }}
                                                </button>
                                            @endif
                                        </div>

                                        @if ($linkFormTopicId === $topic->id)
                                            <form wire:submit="saveLink" class="mt-2 flex flex-wrap items-end gap-2">
                                                <label for="link-form-competency-{{ $topic->id }}" class="flex min-w-[14rem] flex-col gap-1">
                                                    <span class="text-xs font-medium text-charcoal/70">Competency</span>
                                                    <select id="link-form-competency-{{ $topic->id }}" wire:model="linkFormCompetencyId"
                                                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                                                        <option value="">Select a competency</option>
                                                        @foreach ($curriculum->competencies as $competency)
                                                            <option value="{{ $competency->id }}">{{ $competency->code }} - {{ $competency->descriptor }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('linkFormCompetencyId')
                                                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                                                    @enderror
                                                </label>
                                                <button type="submit" class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">Link</button>
                                            </form>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    @else
        {{-- ── Competencies tab ─────────────────────────────────────────── --}}
        @if ($canManage && ! $isPublished)
            <div class="flex justify-end">
                <button type="button" wire:click="toggleCompetencyForm"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    {{ $showCompetencyForm ? 'Hide form' : 'Add competency' }}
                </button>
            </div>
        @endif

        @if ($showCompetencyForm)
            <section aria-label="Add competency" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
                <form wire:submit="saveCompetency" class="space-y-3">
                    <div class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-3">
                        <label for="competency-form-code" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Code</span>
                            <input id="competency-form-code" type="text" wire:model="competencyFormCode"
                                   placeholder="e.g. COMP-1"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('competencyFormCode')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                        <label for="competency-form-descriptor" class="flex flex-col gap-1 sm:col-span-2">
                            <span class="text-xs font-medium text-charcoal/70">Descriptor</span>
                            <input id="competency-form-descriptor" type="text" wire:model="competencyFormDescriptor"
                                   placeholder="e.g. Solves real-life problems using whole numbers"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('competencyFormDescriptor')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">Save competency</button>
                        <button type="button" wire:click="toggleCompetencyForm" class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">Cancel</button>
                    </div>
                </form>
            </section>
        @endif

        @if ($curriculum->competencies->isEmpty())
            <x-empty-state title="No competencies yet"
                           message="Competencies are the flat list of what a learner should master under this curriculum - a short code and a descriptor - and are linked to the topics that develop them.">
                @if ($canManage && ! $isPublished)
                    <x-slot:action>
                        <button type="button" wire:click="toggleCompetencyForm"
                                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                            Add competency
                        </button>
                    </x-slot:action>
                @endif
            </x-empty-state>
        @else
            <div class="min-w-0 overflow-hidden rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="min-w-0 overflow-x-auto">
                    <table class="w-full min-w-[30rem] border-collapse text-sm">
                        <thead class="border-b border-border-primary bg-chrome text-left text-white [&_th]:whitespace-nowrap">
                            <tr>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Code</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Descriptor</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Linked topics</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-secondary [&_td]:align-top">
                            @foreach ($curriculum->competencies as $competency)
                                <tr wire:key="competency-{{ $competency->id }}">
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-charcoal">{{ $competency->code }}</td>
                                    <td class="px-4 py-3 text-charcoal/80">{{ $competency->descriptor }}</td>
                                    <td class="px-4 py-3 text-charcoal/70">
                                        @if ($competency->topics->isEmpty())
                                            <span class="text-charcoal/40">Not linked yet</span>
                                        @else
                                            {{ $competency->topics->pluck('title')->implode(', ') }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
