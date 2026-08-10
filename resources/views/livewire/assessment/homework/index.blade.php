<div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.homework_screen.title') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ __('opes.homework_screen.intro') }}</p>
        </div>
        <button type="button" wire:click="$set('showForm', true)"
                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
            {{ __('opes.homework_screen.set_assignment') }}
        </button>
    </header>

    @if ($message !== '')
        <p class="rounded border border-primary/40 bg-primary/10 p-3 text-sm text-primary" role="status">{{ $message }}</p>
    @endif

    @if ($error !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    {{-- The universal popup-form pattern: a real modal, autosaved on every
         field change, and hold-able mid-fill onto /unfinished-work. --}}
    <x-opes-modal-form wire-model="showForm" :open="$showForm" title="{{ __('opes.homework_screen.set_assignment') }}">
        @if ($resumedFromDraft)
            <p class="mb-3 rounded border border-primary/40 bg-primary/10 p-2 text-xs text-primary">
                {{ __('opes.unfinished_work.resume') }} — {{ __('opes.homework_screen.intro') }}
            </p>
        @endif

        <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.homework_screen.class') }}</span>
                <select wire:model.live="classGroupId" class="mt-1 w-full rounded border border-border-primary p-2">
                    <option value=""></option>
                    @foreach ($classGroups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.homework_screen.subject') }}</span>
                <select wire:model.live="subjectId" class="mt-1 w-full rounded border border-border-primary p-2">
                    <option value=""></option>
                    @foreach ($subjects as $subject)
                        <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm sm:col-span-2">
                <span class="block text-slate-600">{{ __('opes.homework_screen.title_label') }}</span>
                <input type="text" wire:model.live.debounce.800ms="title" class="mt-1 w-full rounded border border-border-primary p-2">
            </label>
            <label class="text-sm sm:col-span-2">
                <span class="block text-slate-600">{{ __('opes.homework_screen.instructions') }}</span>
                <textarea wire:model.live.debounce.800ms="instructions" rows="3" class="mt-1 w-full rounded border border-border-primary p-2"></textarea>
            </label>
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.homework_screen.assigned_on') }}</span>
                <input type="date" wire:model.live="assignedOn" class="mt-1 w-full rounded border border-border-primary p-2">
            </label>
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.homework_screen.due_on') }}</span>
                <input type="date" wire:model.live="dueOn" class="mt-1 w-full rounded border border-border-primary p-2">
            </label>
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.homework_screen.max_score') }}</span>
                <input type="number" step="0.01" wire:model.live.debounce.800ms="maxScore" class="mt-1 w-full rounded border border-border-primary p-2">
            </label>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="create" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                {{ __('opes.homework_screen.save') }}
            </button>
            <button type="button" wire:click="hold" class="rounded border border-primary px-4 py-2 text-sm font-semibold text-primary">
                {{ __('opes.unfinished_work.held') }}
            </button>
            <button type="button" wire:click="$set('showForm', false)" class="rounded border border-border-primary px-4 py-2 text-sm">
                {{ __('opes.homework_screen.cancel') }}
            </button>
            @if ($lastAutosavedAt !== '')
                <span class="ml-auto text-xs text-slate-400" wire:loading.remove>
                    {{ __('opes.notifications.autosaved') }}: {{ $lastAutosavedAt }}
                </span>
            @endif
        </div>
    </x-opes-modal-form>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-lg border border-border-primary bg-white shadow-sm">
            <ul class="max-h-[28rem] divide-y divide-border-primary overflow-y-auto">
                @forelse ($assignments as $assignment)
                    <li>
                        <button type="button" wire:click="$set('selectedAssignmentId', {{ $assignment->id }})"
                                class="w-full p-3 text-left text-sm hover:bg-sand/30 {{ $selected?->id === $assignment->id ? 'bg-sand/40' : '' }}">
                            <span class="block font-medium text-charcoal">{{ $assignment->title }}</span>
                            <span class="block text-xs text-slate-500">{{ __('opes.homework_screen.due') }}: {{ $assignment->due_on->format('Y-m-d') }}</span>
                        </button>
                    </li>
                @empty
                    <li class="p-4 text-center text-sm text-slate-500">{{ __('opes.homework_screen.empty') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="lg:col-span-2 overflow-x-auto rounded-lg border border-border-primary bg-white shadow-sm">
            @if ($selected === null)
                <div class="p-8 text-center text-sm text-slate-500">{{ __('opes.homework_screen.select_an_assignment') }}</div>
            @else
                <div class="border-b border-border-primary p-3">
                    <h2 class="text-sm font-semibold text-charcoal">{{ $selected->title }}</h2>
                    @if ($selected->instructions)
                        <p class="mt-1 text-sm text-slate-600">{{ $selected->instructions }}</p>
                    @endif
                </div>

                <table class="min-w-full text-sm">
                    <thead class="bg-sand/40">
                    <tr>
                        <th class="p-2 text-left font-semibold">{{ __('opes.homework_screen.student') }}</th>
                        <th class="p-2 text-left font-semibold">{{ __('opes.homework_screen.submitted') }}</th>
                        <th class="p-2 text-left font-semibold">{{ __('opes.homework_screen.score') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($submissions as $submission)
                        <tr class="border-t border-border-primary">
                            <td class="p-2">{{ $submission->first_name }} {{ $submission->last_name }}</td>
                            <td class="p-2">
                                {{ $submission->submitted_at ?? __('opes.homework_screen.not_submitted') }}
                                @if ($submission->is_late)
                                    <span class="ml-1 rounded bg-heritage-red/10 px-1.5 py-0.5 text-xs text-heritage-red">{{ __('opes.homework_screen.late') }}</span>
                                @endif
                            </td>
                            <td class="p-2">
                                @if ($submission->graded_at)
                                    <span class="font-mono">{{ $submission->score }}</span>
                                @elseif ($submission->submitted_at)
                                    <div class="flex items-center gap-1">
                                        <input type="number" step="0.01" wire:model="scoreDrafts.{{ $submission->id }}"
                                               class="w-20 rounded border border-border-primary p-1 text-sm">
                                        <button type="button" wire:click="grade({{ $submission->id }})"
                                                class="rounded bg-primary px-2 py-1 text-xs font-semibold text-white">
                                            {{ __('opes.homework_screen.grade') }}
                                        </button>
                                    </div>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="p-4 text-center text-slate-500">{{ __('opes.homework_screen.no_submissions') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            @endif
        </section>
    </div>
</div>
