<div class="min-w-0 space-y-4">

    {{-- ── Breadcrumb ─────────────────────────────────────────────────── --}}
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('alumni.breadcrumb_dashboard') }}</li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <a href="{{ route('alumni.index') }}" class="hover:text-primary">{{ __('alumni.title') }}</a>
            </li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $student->last_name }} {{ $student->first_name }}</span>
            </li>
        </ol>
    </nav>

    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @error('deceased')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
            {{ $message }}
        </p>
    @enderror

    {{-- ── Profile card ───────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-charcoal">{{ $student->last_name }} {{ $student->first_name }}</h1>
                @if ($record->is_deceased)
                    <x-status-pill status="red" :label="__('alumni.status_deceased')"/>
                @elseif ($record->isReachable())
                    <x-status-pill status="ok" :label="__('alumni.status_reachable')"/>
                @else
                    <x-status-pill status="amber" :label="__('alumni.status_unreachable')"/>
                @endif
            </div>
            <p class="mt-1 text-sm text-charcoal/70">
                {{ $student->matricule }} · {{ $record->final_class_group_name }} · {{ $record->academic_year_name }}
            </p>

            <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">{{ __('alumni.graduation_year') }}</dt>
                    <dd class="text-sm font-semibold text-charcoal tabular-nums">{{ $record->graduation_year }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">{{ __('alumni.occupation') }}</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $record->current_occupation ?? __('alumni.none') }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">{{ __('alumni.organisation') }}</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $record->current_organisation ?? __('alumni.none') }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">{{ __('alumni.email') }}</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $record->contact_email ?? __('alumni.none') }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">{{ __('alumni.phone') }}</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $record->contact_phone ?? __('alumni.none') }}</dd>
                </div>
            </dl>

            @if ($record->notes !== null && $record->notes !== '')
                <p class="mt-3 max-w-prose text-sm text-charcoal/70">
                    <span class="font-medium text-charcoal/80">{{ __('alumni.notes') }}:</span> {{ $record->notes }}
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('alumni.index') }}"
               class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ __('alumni.back_to_list') }}
            </a>
            @can(\App\Modules\Identity\Domain\Permission::AlumniManage->value)
                <button type="button" wire:click="toggleContactForm"
                        class="rounded border border-primary px-3 py-1.5 text-sm font-semibold text-primary hover:bg-primary/10">
                    {{ __('alumni.update_contact') }}
                </button>
                @unless ($record->is_deceased)
                    <button type="button" wire:click="markDeceased"
                            wire:confirm="{{ __('alumni.confirm_deceased') }}"
                            class="rounded border border-heritage-red px-3 py-1.5 text-sm font-semibold text-heritage-red hover:bg-heritage-red/10">
                        {{ __('alumni.mark_deceased') }}
                    </button>
                @endunless
            @endcan
        </div>
    </div>

    {{-- ── Contact form ───────────────────────────────────────────────── --}}
    @if ($showContactForm)
        <section aria-label="{{ __('alumni.update_contact') }}" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">{{ __('alumni.update_contact') }}</h2>

            <form wire:submit="saveContact" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="alumni-contact-occupation" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.form_occupation') }}</span>
                        <input id="alumni-contact-occupation" type="text" wire:model="contactOccupation"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('contactOccupation')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    <label for="alumni-contact-organisation" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.form_organisation') }}</span>
                        <input id="alumni-contact-organisation" type="text" wire:model="contactOrganisation"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('contactOrganisation')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    <label for="alumni-contact-email" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.form_email') }}</span>
                        <input id="alumni-contact-email" type="email" wire:model="contactEmail"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('contactEmail')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    <label for="alumni-contact-phone" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.form_phone') }}</span>
                        <input id="alumni-contact-phone" type="text" wire:model="contactPhone"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('contactPhone')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>

                    <label for="alumni-contact-notes" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.form_notes') }}</span>
                        <textarea id="alumni-contact-notes" rows="3" wire:model="contactNotes"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                        @error('contactNotes')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        {{ __('alumni.save') }}
                    </button>
                    <button type="button" wire:click="toggleContactForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        {{ __('alumni.cancel') }}
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- ── Timeline + rail ────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <section aria-label="{{ __('alumni.engagement_timeline') }}"
                 class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5 lg:col-span-2">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('alumni.engagement_timeline') }}</h2>

            @if ($engagements->isEmpty())
                <div class="mt-3">
                    <x-empty-state :message="__('alumni.engagement_empty')"/>
                </div>
            @else
                <ol class="mt-3 space-y-3">
                    @foreach ($engagements as $engagement)
                        <li wire:key="alumni-engagement-{{ $engagement->id }}"
                            class="rounded border border-border-primary p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-charcoal">{{ $engagement->type->label() }}</span>
                                <span class="text-xs tabular-nums text-charcoal/60">{{ $engagement->engaged_on->toDateString() }}</span>
                            </div>
                            <p class="mt-1 text-sm text-charcoal/80">{{ $engagement->note }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <aside class="space-y-4">
            @can(\App\Modules\Identity\Domain\Permission::AlumniManage->value)
                <section aria-label="{{ __('alumni.record_engagement') }}"
                         class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-semibold text-charcoal">{{ __('alumni.record_engagement') }}</h3>

                    <form wire:submit="recordEngagement" class="mt-3 space-y-3">
                        <label for="alumni-engagement-type" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.form_type') }}</span>
                            <select id="alumni-engagement-type" wire:model="engagementType"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                @foreach ($typeOptions as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                            @error('engagementType')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="alumni-engagement-date" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.form_date') }}</span>
                            <input id="alumni-engagement-date" type="date" wire:model="engagedOn"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('engagedOn')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="alumni-engagement-note" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.form_note') }}</span>
                            <textarea id="alumni-engagement-note" rows="3" wire:model="engagementNote"
                                      class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                            @error('engagementNote')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <button type="submit"
                                class="w-full rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                            {{ __('alumni.record_engagement') }}
                        </button>
                    </form>
                </section>
            @endcan

            <section aria-label="{{ __('alumni.graduation') }}"
                     class="rounded border border-border-primary bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">{{ __('alumni.graduation') }}</h3>
                <ul class="space-y-2 text-sm text-charcoal/80">
                    <li class="flex items-center justify-between"><span>{{ __('alumni.graduation_year') }}</span><span class="tabular-nums">{{ $record->graduation_year }}</span></li>
                    <li class="flex items-center justify-between"><span>{{ __('alumni.final_class') }}</span><span>{{ $record->final_class_group_name }}</span></li>
                    <li class="flex items-center justify-between"><span>{{ __('alumni.academic_year') }}</span><span>{{ $record->academic_year_name }}</span></li>
                </ul>
            </section>
        </aside>
    </div>
</div>
