{{-- Student Admission Wizard (frontend images/student admission wizzard.png,
     frontend images/admission wizard.png), docs/specs/07-students.md 6.2.

     The step labels follow 6.2's explicit resolution in the mockup's favour:
     Basic Information / Academic Details / Parent-Guardian / Other Information
     / Documents & Review.

     The mockup's photo tile and document uploader are NOT drawn: this phase
     stores no file, and an upload control that quietly discards what the
     operator selects is worse than an honest absence. Everything else on the
     mockup - the numbered progress rail, the two-column labelled grid, the
     Admission Summary panel, Cancel + green Next bottom-right - is here and is
     fed only by data the row actually holds. --}}

@php
    $lastStep = \App\Modules\Admissions\Domain\WizardStep::LAST;
    $locked = $this->isLocked();
@endphp

<div class="min-w-0 space-y-5">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.admissions_screen.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li>{{ __('opes.admissions_screen.breadcrumb_admissions') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.admissions_screen.breadcrumb_wizard') }}</li>
        </ol>
    </nav>

    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.admissions_screen.title') }}</h1>

    @if ($statusMessage !== '')
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ $statusMessage }}
        </p>
    @endif

    @if ($locked && ! $this->isEnrolled())
        <p class="rounded border border-heritage-yellow/60 bg-heritage-yellow/10 px-3 py-2 text-sm text-charcoal" role="status">
            {{ __('opes.admissions_screen.submitted_notice') }}
        </p>
    @endif

    @if ($this->isEnrolled())
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ __('opes.admissions_screen.enrolled_notice') }}
        </p>
    @endif

    {{-- The numbered progress rail. `aria-current` rather than colour alone:
         09-ui 10 requires the current step to be announced, not merely
         painted green. --}}
    <ol aria-label="{{ __('opes.admissions_screen.title') }}"
        class="flex w-full items-start justify-between gap-1 overflow-x-auto rounded-lg border border-border-primary bg-white px-4 py-5 shadow-sm">
        @foreach ($steps as $stepOption)
            <li class="flex min-w-24 flex-1 flex-col items-center gap-2 text-center"
                @if ($stepOption->value === $currentStep->value) aria-current="step" @endif>
                <div class="flex w-full items-center">
                    <span class="h-px flex-1 {{ $loop->first ? 'bg-transparent' : ($stepOption->value <= $currentStep->value ? 'bg-primary' : 'bg-sand') }}"></span>
                    <span aria-hidden="true"
                          class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border text-sm font-semibold
                                 {{ $stepOption->value === $currentStep->value
                                        ? 'border-chrome bg-chrome text-white'
                                        : ($stepOption->value < $currentStep->value
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border-primary bg-white text-charcoal/50') }}">
                        {{ $stepOption->value }}
                    </span>
                    <span class="h-px flex-1 {{ $loop->last ? 'bg-transparent' : ($stepOption->value < $currentStep->value ? 'bg-primary' : 'bg-sand') }}"></span>
                </div>
                <span class="text-xs {{ $stepOption->value === $currentStep->value ? 'font-semibold text-primary' : 'text-charcoal/60' }}">
                    {{ $stepOption->label(app()->getLocale()) }}
                </span>
            </li>
        @endforeach
    </ol>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <section class="rounded-lg border border-border-primary bg-white p-5 shadow-sm"
                 aria-label="{{ $currentStep->label(app()->getLocale()) }}">
            <h2 class="text-base font-semibold text-charcoal">
                {{ __('opes.admissions_screen.step_counter', ['current' => $currentStep->value, 'total' => $lastStep]) }}:
                {{ $currentStep->label(app()->getLocale()) }}
            </h2>

            {{-- ================= Step 1: Basic Information ================= --}}
            @if ($currentStep->value === 1)
                <h3 class="mt-5 border-b border-border-primary pb-2 text-sm font-semibold text-primary">
                    {{ __('opes.admissions_screen.section_personal') }}
                </h3>

                <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="adm-first-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.first_name') }} <span class="text-heritage-red">*</span></span>
                        <input id="adm-first-name" type="text" wire:model="first_name" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('first_name') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-middle-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.middle_name') }}</span>
                        <input id="adm-middle-name" type="text" wire:model="middle_name" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('middle_name') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-last-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.last_name') }} <span class="text-heritage-red">*</span></span>
                        <input id="adm-last-name" type="text" wire:model="last_name" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('last_name') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-dob" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.date_of_birth') }} <span class="text-heritage-red">*</span></span>
                        <input id="adm-dob" type="date" wire:model="date_of_birth" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('date_of_birth') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-gender" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.gender') }} <span class="text-heritage-red">*</span></span>
                        <select id="adm-gender" wire:model="gender" @disabled($locked)
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.admissions_screen.choose') }}</option>
                            <option value="male">{{ __('opes.admissions_screen.gender_male') }}</option>
                            <option value="female">{{ __('opes.admissions_screen.gender_female') }}</option>
                        </select>
                        @error('gender') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-nationality" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.nationality') }} <span class="text-heritage-red">*</span></span>
                        <input id="adm-nationality" type="text" maxlength="2" wire:model="nationality" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm uppercase text-charcoal focus:border-primary/50"/>
                        @error('nationality') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-place-of-birth" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.place_of_birth') }}</span>
                        <input id="adm-place-of-birth" type="text" wire:model="place_of_birth" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('place_of_birth') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-state-of-origin" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.state_of_origin') }}</span>
                        <input id="adm-state-of-origin" type="text" wire:model="state_of_origin" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('state_of_origin') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-religion" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.religion') }}</span>
                        <input id="adm-religion" type="text" wire:model="religion" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('religion') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-blood-group" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.blood_group') }}</span>
                        <input id="adm-blood-group" type="text" maxlength="5" wire:model="blood_group" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('blood_group') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-genotype" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.genotype') }}</span>
                        <input id="adm-genotype" type="text" maxlength="5" wire:model="genotype" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('genotype') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                </div>
            @endif

            {{-- ================= Step 2: Academic Details ================= --}}
            @if ($currentStep->value === 2)
                <h3 class="mt-5 border-b border-border-primary pb-2 text-sm font-semibold text-primary">
                    {{ __('opes.admissions_screen.section_admission') }}
                </h3>

                <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="adm-year" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.academic_year') }} <span class="text-heritage-red">*</span></span>
                        <select id="adm-year" wire:model.live="academic_year_id" @disabled($locked)
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.admissions_screen.choose') }}</option>
                            @foreach ($academicYears as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('academic_year_id') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-term" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.admission_term') }}</span>
                        <select id="adm-term" wire:model="admission_term_id" @disabled($locked)
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.admissions_screen.none') }}</option>
                            @foreach ($terms as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('admission_term_id') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-section" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.school_section') }}</span>
                        <select id="adm-section" wire:model="school_section_id" @disabled($locked)
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.admissions_screen.none') }}</option>
                            @foreach ($sections as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('school_section_id') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-level" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.class_level') }} <span class="text-heritage-red">*</span></span>
                        <select id="adm-level" wire:model.live="class_level_id" @disabled($locked)
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.admissions_screen.choose') }}</option>
                            @foreach ($classLevels as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('class_level_id') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-stream" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.stream') }}</span>
                        <select id="adm-stream" wire:model="stream_id" @disabled($locked)
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.admissions_screen.none') }}</option>
                            @foreach ($streams as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('stream_id') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-category" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.category') }}</span>
                        <input id="adm-category" type="text" wire:model="category" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('category') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.admission_date') }} <span class="text-heritage-red">*</span></span>
                        <input id="adm-date" type="date" wire:model="admission_date" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('admission_date') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-roll" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.proposed_roll_number') }}</span>
                        <input id="adm-roll" type="number" min="1" wire:model="proposed_roll_number" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('proposed_roll_number') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                </div>
            @endif

            {{-- ================= Step 3: Parent / Guardian ================= --}}
            @if ($currentStep->value === 3)
                <h3 class="mt-5 border-b border-border-primary pb-2 text-sm font-semibold text-primary">
                    {{ __('opes.admissions_screen.section_guardians') }}
                </h3>

                @error('guardians') <p class="mt-3 text-xs text-heritage-red">{{ $message }}</p> @enderror

                @foreach ($guardians as $index => $guardian)
                    <fieldset class="mt-4 rounded border border-border-primary p-4">
                        <legend class="px-1 text-xs font-semibold text-charcoal/70">
                            {{ __('opes.admissions_screen.guardian_number', ['position' => $index + 1]) }}
                        </legend>

                        <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                            <label for="adm-g{{ $index }}-first" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.guardian_first_name') }} <span class="text-heritage-red">*</span></span>
                                <input id="adm-g{{ $index }}-first" type="text" wire:model="guardians.{{ $index }}.first_name" @disabled($locked)
                                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                @error('guardians.'.$index.'.first_name') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                            </label>

                            <label for="adm-g{{ $index }}-last" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.guardian_last_name') }} <span class="text-heritage-red">*</span></span>
                                <input id="adm-g{{ $index }}-last" type="text" wire:model="guardians.{{ $index }}.last_name" @disabled($locked)
                                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                @error('guardians.'.$index.'.last_name') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                            </label>

                            <label for="adm-g{{ $index }}-gender" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.guardian_gender') }} <span class="text-heritage-red">*</span></span>
                                <select id="adm-g{{ $index }}-gender" wire:model="guardians.{{ $index }}.gender" @disabled($locked)
                                        class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                    <option value="">{{ __('opes.admissions_screen.choose') }}</option>
                                    <option value="male">{{ __('opes.admissions_screen.gender_male') }}</option>
                                    <option value="female">{{ __('opes.admissions_screen.gender_female') }}</option>
                                </select>
                                @error('guardians.'.$index.'.gender') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                            </label>

                            <label for="adm-g{{ $index }}-relationship" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.relationship') }} <span class="text-heritage-red">*</span></span>
                                <select id="adm-g{{ $index }}-relationship" wire:model.live="guardians.{{ $index }}.relationship" @disabled($locked)
                                        class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                    <option value="">{{ __('opes.admissions_screen.choose') }}</option>
                                    @foreach ($relationships as $relationship)
                                        <option value="{{ $relationship->value }}">{{ $relationship->label(app()->getLocale()) }}</option>
                                    @endforeach
                                </select>
                                @error('guardians.'.$index.'.relationship') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                            </label>

                            @if (($guardian['relationship'] ?? '') === 'other')
                                <label for="adm-g{{ $index }}-relationship-other" class="flex flex-col gap-1">
                                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.relationship_other') }} <span class="text-heritage-red">*</span></span>
                                    <input id="adm-g{{ $index }}-relationship-other" type="text" wire:model="guardians.{{ $index }}.relationship_other" @disabled($locked)
                                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                    @error('guardians.'.$index.'.relationship_other') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                                </label>
                            @endif

                            <label for="adm-g{{ $index }}-phone" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.phone') }} <span class="text-heritage-red">*</span></span>
                                <input id="adm-g{{ $index }}-phone" type="tel" wire:model="guardians.{{ $index }}.phone" @disabled($locked)
                                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                @error('guardians.'.$index.'.phone') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                            </label>

                            <label for="adm-g{{ $index }}-email" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.email') }}</span>
                                <input id="adm-g{{ $index }}-email" type="email" wire:model="guardians.{{ $index }}.email" @disabled($locked)
                                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                @error('guardians.'.$index.'.email') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                            </label>

                            <label for="adm-g{{ $index }}-occupation" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.occupation') }}</span>
                                <input id="adm-g{{ $index }}-occupation" type="text" wire:model="guardians.{{ $index }}.occupation" @disabled($locked)
                                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                @error('guardians.'.$index.'.occupation') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                            </label>

                            <label for="adm-g{{ $index }}-id-number" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.id_number') }}</span>
                                <input id="adm-g{{ $index }}-id-number" type="text" wire:model="guardians.{{ $index }}.id_number" @disabled($locked)
                                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                @error('guardians.'.$index.'.id_number') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                            </label>

                            <label for="adm-g{{ $index }}-address" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.address_line') }}</span>
                                <input id="adm-g{{ $index }}-address" type="text" wire:model="guardians.{{ $index }}.address_line" @disabled($locked)
                                       class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                @error('guardians.'.$index.'.address_line') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                            </label>
                        </div>

                        <fieldset class="mt-4 border-t border-border-primary pt-3">
                            <legend class="sr-only">{{ __('opes.admissions_screen.authorisations') }}</legend>
                            <p class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.authorisations') }}</p>

                            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ([
                                    'is_primary' => __('opes.admissions_screen.is_primary'),
                                    'has_custody' => __('opes.admissions_screen.has_custody'),
                                    'receives_reports' => __('opes.admissions_screen.receives_reports'),
                                    'receives_invoices' => __('opes.admissions_screen.receives_invoices'),
                                    'is_emergency_contact' => __('opes.admissions_screen.is_emergency_contact'),
                                    'is_authorised_for_pickup' => __('opes.admissions_screen.is_authorised_for_pickup'),
                                    'is_fee_payer' => __('opes.admissions_screen.is_fee_payer'),
                                ] as $flag => $flagLabel)
                                    <label for="adm-g{{ $index }}-{{ str_replace('_', '-', $flag) }}" class="flex items-center gap-2">
                                        <input id="adm-g{{ $index }}-{{ str_replace('_', '-', $flag) }}" type="checkbox"
                                               wire:model="guardians.{{ $index }}.{{ $flag }}" @disabled($locked)
                                               class="rounded border-border-primary text-primary"/>
                                        <span class="text-xs text-charcoal/80">{{ $flagLabel }}</span>
                                    </label>
                                @endforeach
                            </div>

                            @error('guardians.'.$index.'.has_custody') <p class="mt-2 text-xs text-heritage-red">{{ $message }}</p> @enderror
                        </fieldset>

                        @unless ($locked)
                            <button type="button" wire:click="removeGuardian({{ $index }})"
                                    class="mt-3 rounded border border-border-primary px-3 py-1 text-xs font-medium text-charcoal hover:border-heritage-red/50 hover:text-heritage-red">
                                {{ __('opes.admissions_screen.remove_guardian') }}
                            </button>
                        @endunless
                    </fieldset>
                @endforeach

                @unless ($locked)
                    <button type="button" wire:click="addGuardian"
                            class="mt-4 rounded border border-primary/40 px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary/5">
                        {{ __('opes.admissions_screen.add_guardian') }}
                    </button>
                @endunless
            @endif

            {{-- ================= Step 4: Other Information ================= --}}
            @if ($currentStep->value === 4)
                <h3 class="mt-5 border-b border-border-primary pb-2 text-sm font-semibold text-primary">
                    {{ __('opes.admissions_screen.section_previous_school') }}
                </h3>

                <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="adm-prev-school" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.previous_school_name') }}</span>
                        <input id="adm-prev-school" type="text" wire:model="previous_school_name" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('previous_school_name') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-last-class" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.last_class_completed') }}</span>
                        <input id="adm-last-class" type="text" wire:model="last_class_completed" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('last_class_completed') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-year-completed" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.year_completed') }}</span>
                        <input id="adm-year-completed" type="number" wire:model="year_completed" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('year_completed') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-reason-leaving" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.reason_for_leaving') }}</span>
                        <input id="adm-reason-leaving" type="text" wire:model="reason_for_leaving" @disabled($locked)
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('reason_for_leaving') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-special-info" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.special_information') }}</span>
                        <textarea id="adm-special-info" rows="3" wire:model="special_information" @disabled($locked)
                                  placeholder="{{ __('opes.admissions_screen.special_information_placeholder') }}"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                        @error('special_information') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                </div>
            @endif

            {{-- ============= Step 5: Documents & Review ============= --}}
            @if ($currentStep->value === 5)
                <h3 class="mt-5 border-b border-border-primary pb-2 text-sm font-semibold text-primary">
                    {{ __('opes.admissions_screen.section_photo') }}
                </h3>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-[auto_1fr]">
                    {{-- wire:key on the wrapper so Livewire replaces the
                         thumbnail when the path changes rather than reusing a
                         stale img element. --}}
                    <span wire:key="applicant-photo-{{ $photo_path }}"
                          class="flex h-28 w-24 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border-primary bg-sand">
                        @if ($photoUpload !== null)
                            <img src="{{ $photoUpload->temporaryUrl() }}" alt="" class="max-h-full max-w-full object-contain">
                        @elseif ($photo_path !== '')
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($photo_path) }}"
                                 alt="{{ __('opes.admissions_screen.photo') }}"
                                 class="max-h-full max-w-full object-contain">
                        @else
                            <span class="px-2 text-center text-xs text-charcoal/40">{{ __('opes.admissions_screen.photo_none') }}</span>
                        @endif
                    </span>

                    <div class="min-w-0">
                        @if (! $locked)
                            <input type="file" wire:model="photoUpload"
                                   accept="image/png,image/jpeg,image/webp"
                                   class="block w-full text-sm text-charcoal file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-primary/90"/>

                            <div wire:loading wire:target="photoUpload" class="mt-1 text-xs text-text-secondary">
                                {{ __('opes.admissions_screen.photo_uploading') }}
                            </div>

                            <p class="mt-1 text-xs text-charcoal/50">{{ __('opes.admissions_screen.photo_hint') }}</p>

                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="savePhoto"
                                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                    {{ __('opes.admissions_screen.photo_save') }}
                                </button>

                                @if ($photo_path !== '' || $photoUpload !== null)
                                    <button type="button" wire:click="removePhoto"
                                            class="text-xs font-medium text-heritage-red hover:underline">
                                        {{ __('opes.admissions_screen.photo_remove') }}
                                    </button>
                                @endif
                            </div>
                        @else
                            <p class="text-xs text-charcoal/50">{{ __('opes.admissions_screen.photo_locked') }}</p>
                        @endif

                        @error('photoUpload') <p class="mt-2 text-xs text-heritage-red">{{ $message }}</p> @enderror
                    </div>
                </div>

                <h3 class="mt-6 border-b border-border-primary pb-2 text-sm font-semibold text-primary">
                    {{ __('opes.admissions_screen.section_review') }}
                </h3>

                <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                    <div class="sm:col-span-2 pt-2 text-xs font-semibold uppercase tracking-wide text-charcoal/50">
                        {{ __('opes.admissions_screen.review_identity') }}
                    </div>
                    @foreach ([
                        __('opes.admissions_screen.first_name') => $first_name,
                        __('opes.admissions_screen.middle_name') => $middle_name,
                        __('opes.admissions_screen.last_name') => $last_name,
                        __('opes.admissions_screen.date_of_birth') => $date_of_birth,
                        __('opes.admissions_screen.gender') => $gender,
                        __('opes.admissions_screen.nationality') => $nationality,
                        __('opes.admissions_screen.place_of_birth') => $place_of_birth,
                        __('opes.admissions_screen.state_of_origin') => $state_of_origin,
                        __('opes.admissions_screen.religion') => $religion,
                        __('opes.admissions_screen.blood_group') => $blood_group,
                        __('opes.admissions_screen.genotype') => $genotype,
                    ] as $reviewLabel => $reviewValue)
                        <div class="flex justify-between gap-4 border-b border-border-primary/70 py-1 text-sm">
                            <dt class="text-charcoal/60">{{ $reviewLabel }}</dt>
                            <dd class="text-right font-medium text-charcoal">{{ $reviewValue !== '' ? $reviewValue : __('opes.admissions_screen.review_not_provided') }}</dd>
                        </div>
                    @endforeach

                    <div class="sm:col-span-2 pt-4 text-xs font-semibold uppercase tracking-wide text-charcoal/50">
                        {{ __('opes.admissions_screen.review_academics') }}
                    </div>
                    @foreach ([
                        __('opes.admissions_screen.academic_year') => $academicYears[(int) $academic_year_id] ?? '',
                        __('opes.admissions_screen.admission_term') => $terms[(int) $admission_term_id] ?? '',
                        __('opes.admissions_screen.class_level') => $classLevels[(int) $class_level_id] ?? '',
                        __('opes.admissions_screen.stream') => $streams[(int) $stream_id] ?? '',
                        __('opes.admissions_screen.category') => $category,
                        __('opes.admissions_screen.admission_date') => $admission_date,
                        __('opes.admissions_screen.proposed_roll_number') => $proposed_roll_number,
                    ] as $reviewLabel => $reviewValue)
                        <div class="flex justify-between gap-4 border-b border-border-primary/70 py-1 text-sm">
                            <dt class="text-charcoal/60">{{ $reviewLabel }}</dt>
                            <dd class="text-right font-medium text-charcoal">{{ $reviewValue !== '' ? $reviewValue : __('opes.admissions_screen.review_not_provided') }}</dd>
                        </div>
                    @endforeach

                    <div class="sm:col-span-2 pt-4 text-xs font-semibold uppercase tracking-wide text-charcoal/50">
                        {{ __('opes.admissions_screen.review_previous_school') }}
                    </div>
                    @foreach ([
                        __('opes.admissions_screen.previous_school_name') => $previous_school_name,
                        __('opes.admissions_screen.last_class_completed') => $last_class_completed,
                        __('opes.admissions_screen.year_completed') => $year_completed,
                        __('opes.admissions_screen.reason_for_leaving') => $reason_for_leaving,
                        __('opes.admissions_screen.special_information') => $special_information,
                    ] as $reviewLabel => $reviewValue)
                        <div class="flex justify-between gap-4 border-b border-border-primary/70 py-1 text-sm">
                            <dt class="text-charcoal/60">{{ $reviewLabel }}</dt>
                            <dd class="text-right font-medium text-charcoal">{{ $reviewValue !== '' ? $reviewValue : __('opes.admissions_screen.review_not_provided') }}</dd>
                        </div>
                    @endforeach
                </dl>

                <h4 class="mt-6 text-xs font-semibold uppercase tracking-wide text-charcoal/50">
                    {{ __('opes.admissions_screen.review_guardians') }}
                </h4>
                <ul class="mt-2 space-y-1">
                    @forelse ($guardians as $guardian)
                        <li class="flex flex-wrap items-center gap-2 border-b border-border-primary/70 py-1 text-sm text-charcoal">
                            <span class="font-medium">{{ trim(($guardian['first_name'] ?? '').' '.($guardian['last_name'] ?? '')) }}</span>
                            <span class="text-charcoal/60">{{ $guardian['relationship'] ?? '' }}</span>
                            <span class="text-charcoal/60">{{ $guardian['phone'] ?? '' }}</span>
                            @if ($guardian['is_primary'] ?? false)
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                                    {{ __('opes.admissions_screen.is_primary') }}
                                </span>
                            @endif
                        </li>
                    @empty
                        <li class="py-1 text-sm text-charcoal/60">{{ __('opes.admissions_screen.review_none') }}</li>
                    @endforelse
                </ul>

                <h4 class="mt-6 text-xs font-semibold uppercase tracking-wide text-charcoal/50">
                    {{ __('opes.admissions_screen.section_documents') }}
                </h4>
                <ul class="mt-2 space-y-1">
                    @forelse ($application?->documents ?? [] as $document)
                        <li class="border-b border-border-primary/70 py-1 text-sm text-charcoal">{{ $document->original_name }}</li>
                    @empty
                        <li class="py-1 text-sm text-charcoal/60">{{ __('opes.admissions_screen.no_documents') }}</li>
                    @endforelse
                </ul>

                @if ($application !== null && $application->status->isConvertible())
                    <h4 class="mt-6 border-b border-border-primary pb-2 text-sm font-semibold text-primary">
                        {{ __('opes.admissions_screen.section_enrolment') }}
                    </h4>

                    <label for="adm-class-group" class="mt-4 flex max-w-sm flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.class_group') }} <span class="text-heritage-red">*</span></span>
                        <select id="adm-class-group" wire:model="class_group_id"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.admissions_screen.choose') }}</option>
                            @foreach ($classGroups as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs text-charcoal/50">{{ __('opes.admissions_screen.class_group_hint') }}</span>
                        @error('class_group_id') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="adm-rejection-reason" class="mt-4 flex max-w-sm flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.admissions_screen.rejection_reason') }}</span>
                        <input id="adm-rejection-reason" type="text" wire:model="rejection_reason"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('decision_reason') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                @endif

                @error('status') <p class="mt-3 text-xs text-heritage-red">{{ $message }}</p> @enderror
                @error('completed_step') <p class="mt-3 text-xs text-heritage-red">{{ $message }}</p> @enderror
            @endif

            {{-- Cancel + green Next, bottom-right, exactly as the mockup. --}}
            <div class="mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-border-primary pt-5">
                <a href="{{ route('dashboard') }}"
                   class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    {{ __('opes.admissions_screen.cancel') }}
                </a>

                @if ($currentStep->value > 1)
                    <button type="button" wire:click="back"
                            class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.admissions_screen.back') }}
                    </button>
                @endif

                @if ($currentStep->value < $lastStep && ! $locked)
                    <button type="button" wire:click="next"
                            class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        {{ __('opes.admissions_screen.save_next') }}
                    </button>
                @endif

                @if ($currentStep->value === $lastStep && $application !== null && $application->status->isEditable())
                    <button type="button" wire:click="submit"
                            class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        {{ __('opes.admissions_screen.submit') }}
                    </button>
                @endif

                @if ($currentStep->value === $lastStep && $application !== null && $application->status->isConvertible())
                    <button type="button" wire:click="reject"
                            class="rounded border border-heritage-red/50 px-4 py-1.5 text-sm font-medium text-heritage-red hover:bg-heritage-red/5">
                        {{ __('opes.admissions_screen.reject') }}
                    </button>

                    <button type="button" wire:click="confirm"
                            class="rounded border border-chrome bg-chrome px-4 py-1.5 text-sm font-medium text-white hover:bg-chrome-light">
                        {{ __('opes.admissions_screen.confirm_convert') }}
                    </button>
                @endif
            </div>
        </section>

        {{-- Admission Summary panel: re-rendered from the partially saved row,
             per 6.2. Blank fields say so rather than being hidden - the panel's
             job is to show what is still missing. --}}
        <aside class="h-fit rounded-lg border border-border-primary bg-ivory p-5 shadow-sm"
               aria-label="{{ __('opes.admissions_screen.summary_title') }}">
            <h2 class="border-b border-border-primary pb-2 text-sm font-semibold text-chrome">
                {{ __('opes.admissions_screen.summary_title') }}
            </h2>

            <p class="mt-3 text-base font-semibold text-charcoal">
                {{ $application?->fullName() ?: __('opes.admissions_screen.summary_no_name') }}
            </p>

            <p class="mt-1 text-sm font-medium text-primary">
                {{ $application?->application_no ?? $previewNumber ?? '' }}
            </p>
            @if ($application?->application_no === null)
                <p class="mt-1 text-xs text-charcoal/50">
                    {{ __('opes.admissions_screen.application_no_auto') }} &mdash;
                    {{ __('opes.admissions_screen.application_no_note') }}
                </p>
            @endif

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between gap-3 border-b border-border-primary pb-1">
                    <dt class="text-charcoal/60">{{ __('opes.admissions_screen.date_of_birth') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $date_of_birth !== '' ? $date_of_birth : __('opes.ui.no_data') }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-border-primary pb-1">
                    <dt class="text-charcoal/60">{{ __('opes.admissions_screen.gender') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $gender !== '' ? $gender : __('opes.ui.no_data') }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-border-primary pb-1">
                    <dt class="text-charcoal/60">{{ __('opes.admissions_screen.class_level') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $classLevels[(int) $class_level_id] ?? __('opes.ui.no_data') }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-border-primary pb-1">
                    <dt class="text-charcoal/60">{{ __('opes.admissions_screen.academic_year') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $academicYears[(int) $academic_year_id] ?? __('opes.ui.no_data') }}</dd>
                </div>
                @if ($application !== null)
                    <div class="flex justify-between gap-3 border-b border-border-primary pb-1">
                        <dt class="text-charcoal/60">{{ __('opes.users.status_label') }}</dt>
                        <dd class="font-medium text-charcoal">{{ $application->status->label(app()->getLocale()) }}</dd>
                    </div>
                @endif
            </dl>

            <p class="mt-4 rounded border border-primary/30 bg-primary/5 px-3 py-2 text-xs text-charcoal/80">
                {{ __('opes.admissions_screen.summary_hint') }}
            </p>
        </aside>
    </div>
</div>
