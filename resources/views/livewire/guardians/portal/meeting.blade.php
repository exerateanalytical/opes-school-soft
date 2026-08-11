<div class="min-w-0 space-y-4">
    <div class="min-w-0">
        <a href="{{ route('portal.children.profile', $studentId) }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {{ $childName }}
        </a>

        <h1 class="mt-1 text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.meeting_title') }}</h1>
    </div>

    @if (session('portal-status'))
        <p class="rounded border border-success/30 bg-success-bg px-4 py-2 text-sm text-success">{{ session('portal-status') }}</p>
    @endif

    {{-- Said before they submit, not after: the time is a preference. A parent
         who believed they had reserved a slot and arrived to an empty office
         would rightly be furious. --}}
    <p class="rounded border border-border-secondary bg-surface-green px-4 py-3 text-sm text-charcoal/70">
        {{ __('opes.guardian_portal.meeting_intro') }}
    </p>

    <form wire:submit="submit" class="space-y-4 rounded border border-border-primary bg-white p-4 shadow-sm">
        <div>
            <p class="text-xs font-medium text-charcoal/70">{{ __('opes.guardian_portal.meeting_child') }}</p>
            <p class="text-sm font-semibold text-charcoal">{{ $childName }}</p>
        </div>

        <div>
            <label for="portal-meeting-when" class="block text-xs font-medium text-charcoal/70">
                {{ __('opes.guardian_portal.meeting_when') }}
            </label>
            <input id="portal-meeting-when" type="datetime-local" wire:model="preferredAt"
                   class="mt-1 w-full rounded border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none sm:w-72">
            @error('preferredAt')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="portal-meeting-type" class="block text-xs font-medium text-charcoal/70">
                {{ __('opes.guardian_portal.meeting_title') }}
            </label>
            <select id="portal-meeting-type" wire:model="meetingType"
                    class="mt-1 w-full rounded border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none sm:w-72">
                @foreach (['parent_teacher', 'disciplinary', 'financial', 'admission', 'other'] as $type)
                    <option value="{{ $type }}">{{ __('opes.guardians_screen.meeting_type_'.$type) }}</option>
                @endforeach
            </select>
            @error('meetingType')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="portal-meeting-agenda" class="block text-xs font-medium text-charcoal/70">
                {{ __('opes.guardian_portal.meeting_agenda') }}
            </label>
            <textarea id="portal-meeting-agenda" wire:model="agenda" rows="4" maxlength="2000"
                      class="mt-1 w-full rounded border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none"></textarea>
            @error('agenda')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex justify-end">
            <button type="submit"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-chrome-light">
                {{ __('opes.guardian_portal.meeting_submit') }}
            </button>
        </div>
    </form>
</div>
