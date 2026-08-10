<div class="space-y-6">
    <header>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.pta_screen.title') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('opes.pta_screen.intro') }}</p>
    </header>

    @if ($message !== '')
        <p class="rounded border border-primary/40 bg-primary/10 p-3 text-sm text-primary" role="status">{{ $message }}</p>
    @endif

    @if ($error !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    <nav class="flex gap-2 border-b border-border-primary">
        <button type="button" wire:click="$set('tab', 'meetings')"
                class="px-3 py-2 text-sm font-semibold {{ $tab === 'meetings' ? 'border-b-2 border-primary text-primary' : 'text-slate-600' }}">
            {{ __('opes.pta_screen.tab_meetings') }}
        </button>
        <button type="button" wire:click="$set('tab', 'officers')"
                class="px-3 py-2 text-sm font-semibold {{ $tab === 'officers' ? 'border-b-2 border-primary text-primary' : 'text-slate-600' }}">
            {{ __('opes.pta_screen.tab_officers') }}
        </button>
    </nav>

    @if ($tab === 'meetings')
        <div class="flex justify-end">
            <button type="button" wire:click="$set('showMeetingForm', true)"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                {{ __('opes.pta_screen.schedule_meeting') }}
            </button>
        </div>

        @if ($showMeetingForm)
            <section class="rounded-lg border border-primary/40 bg-primary/5 p-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm sm:col-span-2">
                        <span class="block text-slate-600">{{ __('opes.pta_screen.meeting_title') }}</span>
                        <input type="text" wire:model="title" class="mt-1 w-full rounded border border-border-primary p-2">
                    </label>
                    <label class="text-sm">
                        <span class="block text-slate-600">{{ __('opes.pta_screen.date') }}</span>
                        <input type="date" wire:model="meetingDate" class="mt-1 w-full rounded border border-border-primary p-2">
                    </label>
                    <label class="text-sm">
                        <span class="block text-slate-600">{{ __('opes.pta_screen.location') }}</span>
                        <input type="text" wire:model="location" class="mt-1 w-full rounded border border-border-primary p-2">
                    </label>
                    <label class="text-sm sm:col-span-2">
                        <span class="block text-slate-600">{{ __('opes.pta_screen.agenda') }}</span>
                        <textarea wire:model="agenda" rows="2" class="mt-1 w-full rounded border border-border-primary p-2"></textarea>
                    </label>
                </div>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="scheduleMeeting" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                        {{ __('opes.pta_screen.save') }}
                    </button>
                    <button type="button" wire:click="$set('showMeetingForm', false)" class="rounded border border-border-primary px-4 py-2 text-sm">
                        {{ __('opes.pta_screen.cancel') }}
                    </button>
                </div>
            </section>
        @endif

        <section class="space-y-3">
            @forelse ($meetings as $meeting)
                <article class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-semibold text-charcoal">{{ $meeting->title }}</h2>
                            <p class="text-xs text-slate-500">{{ $meeting->meeting_date->format('Y-m-d') }} @if($meeting->location) · {{ $meeting->location }} @endif</p>
                        </div>
                        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $meeting->status === 'held' ? 'bg-heritage-green text-white' : 'bg-sand text-charcoal' }}">
                            {{ $meeting->status }}
                        </span>
                    </div>

                    @if ($meeting->agenda)
                        <p class="mt-2 text-sm text-slate-600"><strong>{{ __('opes.pta_screen.agenda') }}:</strong> {{ $meeting->agenda }}</p>
                    @endif

                    @if ($meeting->status === 'held')
                        <p class="mt-2 text-sm text-slate-700"><strong>{{ __('opes.pta_screen.minutes') }}:</strong> {{ $meeting->minutes }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('opes.pta_screen.attendees') }}: {{ $meeting->attendee_count }}</p>
                    @else
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <textarea wire:model="minutesDrafts.{{ $meeting->id }}" rows="2"
                                      placeholder="{{ __('opes.pta_screen.minutes') }}"
                                      class="flex-1 rounded border border-border-primary p-2 text-sm"></textarea>
                            <input type="number" wire:model="attendeeDrafts.{{ $meeting->id }}"
                                   placeholder="{{ __('opes.pta_screen.attendees') }}"
                                   class="w-24 rounded border border-border-primary p-2 text-sm">
                            <button type="button" wire:click="recordMinutes({{ $meeting->id }})"
                                    class="rounded bg-primary px-3 py-2 text-sm font-semibold text-white">
                                {{ __('opes.pta_screen.mark_held') }}
                            </button>
                        </div>
                    @endif
                </article>
            @empty
                <p class="rounded border border-dashed border-border-primary p-6 text-center text-sm text-slate-500">{{ __('opes.pta_screen.no_meetings') }}</p>
            @endforelse
        </section>
    @else
        <div class="flex justify-end">
            <button type="button" wire:click="$set('showOfficerForm', true)"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                {{ __('opes.pta_screen.appoint_officer') }}
            </button>
        </div>

        @if ($showOfficerForm)
            <section class="rounded-lg border border-primary/40 bg-primary/5 p-4">
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">
                        <span class="block text-slate-600">{{ __('opes.pta_screen.guardian') }}</span>
                        <select wire:model="officerGuardianId" class="mt-1 w-full rounded border border-border-primary p-2">
                            <option value=""></option>
                            @foreach ($guardians as $guardian)
                                <option value="{{ $guardian->id }}">{{ $guardian->first_name }} {{ $guardian->last_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="block text-slate-600">{{ __('opes.pta_screen.office') }}</span>
                        <input type="text" wire:model="office" placeholder="President" class="mt-1 w-full rounded border border-border-primary p-2">
                    </label>
                    <label class="text-sm">
                        <span class="block text-slate-600">{{ __('opes.pta_screen.term_starts') }}</span>
                        <input type="date" wire:model="termStartsOn" class="mt-1 w-full rounded border border-border-primary p-2">
                    </label>
                </div>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="appointOfficer" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                        {{ __('opes.pta_screen.save') }}
                    </button>
                    <button type="button" wire:click="$set('showOfficerForm', false)" class="rounded border border-border-primary px-4 py-2 text-sm">
                        {{ __('opes.pta_screen.cancel') }}
                    </button>
                </div>
            </section>
        @endif

        <section class="overflow-x-auto rounded-lg border border-border-primary bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-sand/40">
                <tr>
                    <th class="p-2 text-left font-semibold">{{ __('opes.pta_screen.office') }}</th>
                    <th class="p-2 text-left font-semibold">{{ __('opes.pta_screen.guardian') }}</th>
                    <th class="p-2 text-left font-semibold">{{ __('opes.pta_screen.term_starts') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($officers as $officer)
                    <tr class="border-t border-border-primary">
                        <td class="p-2">{{ $officer->office }}</td>
                        <td class="p-2">{{ $officer->first_name }} {{ $officer->last_name }}</td>
                        <td class="p-2">{{ $officer->term_starts_on }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="p-4 text-center text-slate-500">{{ __('opes.pta_screen.no_officers') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    @endif
</div>
