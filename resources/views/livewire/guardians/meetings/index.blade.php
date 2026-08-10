<div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.meetings_screen.title') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ __('opes.meetings_screen.intro') }}</p>
        </div>
        <button type="button" wire:click="$set('showForm', true)"
                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
            {{ __('opes.meetings_screen.schedule') }}
        </button>
    </header>

    @if ($message !== '')
        <p class="rounded border border-primary/40 bg-primary/10 p-3 text-sm text-primary" role="status">{{ $message }}</p>
    @endif

    @if ($error !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    @if ($showForm)
        <section class="rounded-lg border border-primary/40 bg-primary/5 p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="block text-slate-600">{{ __('opes.meetings_screen.guardian') }}</span>
                    <select wire:model="guardianId" class="mt-1 w-full rounded border border-sand p-2">
                        <option value=""></option>
                        @foreach ($guardians as $guardian)
                            <option value="{{ $guardian->id }}">{{ $guardian->first_name }} {{ $guardian->last_name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-slate-600">{{ __('opes.meetings_screen.type') }}</span>
                    <select wire:model="type" class="mt-1 w-full rounded border border-sand p-2">
                        @foreach ($types as $t)
                            <option value="{{ $t->value }}">{{ $t->value }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-slate-600">{{ __('opes.meetings_screen.when') }}</span>
                    <input type="datetime-local" wire:model="scheduledAt" class="mt-1 w-full rounded border border-sand p-2">
                </label>
                <label class="text-sm">
                    <span class="block text-slate-600">{{ __('opes.meetings_screen.location') }}</span>
                    <input type="text" wire:model="location" class="mt-1 w-full rounded border border-sand p-2">
                </label>
                <label class="text-sm sm:col-span-2">
                    <span class="block text-slate-600">{{ __('opes.meetings_screen.agenda') }}</span>
                    <textarea wire:model="agenda" rows="2" class="mt-1 w-full rounded border border-sand p-2"></textarea>
                </label>
            </div>
            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="schedule" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                    {{ __('opes.meetings_screen.save') }}
                </button>
                <button type="button" wire:click="$set('showForm', false)" class="rounded border border-sand px-4 py-2 text-sm">
                    {{ __('opes.meetings_screen.cancel') }}
                </button>
            </div>
        </section>
    @endif

    <section class="overflow-x-auto rounded-lg border border-sand bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-sand/40">
            <tr>
                <th class="p-2 text-left font-semibold">{{ __('opes.meetings_screen.guardian') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.meetings_screen.type') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.meetings_screen.when') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.meetings_screen.status') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.meetings_screen.minutes') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($meetings as $meeting)
                <tr class="border-t border-sand align-top">
                    <td class="p-2">{{ $meeting->first_name }} {{ $meeting->last_name }}</td>
                    <td class="p-2">{{ $meeting->meeting_type }}</td>
                    <td class="p-2">{{ $meeting->scheduled_at }}</td>
                    <td class="p-2">{{ $meeting->status }}</td>
                    <td class="p-2">
                        @if ($meeting->status === 'scheduled')
                            <div class="flex items-center gap-1">
                                <input type="text" wire:model="minutesDrafts.{{ $meeting->id }}"
                                       placeholder="{{ __('opes.meetings_screen.minutes') }}"
                                       class="w-48 rounded border border-sand p-1 text-xs">
                                <button type="button" wire:click="recordHeld({{ $meeting->id }})"
                                        class="rounded bg-primary px-2 py-1 text-xs font-semibold text-white">
                                    {{ __('opes.meetings_screen.mark_held') }}
                                </button>
                            </div>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-4 text-center text-slate-500">{{ __('opes.meetings_screen.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
