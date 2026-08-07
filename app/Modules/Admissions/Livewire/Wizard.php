<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Livewire;

use App\Modules\Admissions\Actions\ConvertApplication;
use App\Modules\Admissions\Actions\RejectApplication;
use App\Modules\Admissions\Actions\SaveApplicationStep;
use App\Modules\Admissions\Actions\SubmitApplication;
use App\Modules\Admissions\Domain\ApplicantRelationship;
use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Domain\WizardStep;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The 5-step admission wizard, docs/specs/07-students.md 6.2 and 11.4.
 *
 * Three properties of the spec drive the whole design:
 *
 *  - **Draft persistence.** Each Next is a partial save through
 *    SaveApplicationStep, and the row's id rides in the query string. A reload
 *    - a power cut, a closed laptop, a browser crash - re-mounts, finds the
 *    row and resumes at the step the ROW says it reached, not at step 1.
 *  - **Validation lives in the Action, not here.** The component calls
 *    SaveApplicationStep and lets its ValidationException surface; Livewire
 *    renders it into the same error bag an inline `$this->validate()` would
 *    have used. That is why an API caller and an operator get identical rules,
 *    and why the rules cannot drift between the two.
 *  - **Nothing is fabricated.** The mockup's photo tile, document uploader and
 *    notification bell have no backing behaviour in this phase and are not
 *    drawn as decoration. What is on screen is what the row can hold.
 *
 * Authorisation is checked in mount() AND in every write. A Livewire component
 * can be reached directly, not only through the route it is registered
 * against, so the route's `can:admissions.manage` middleware is not the only
 * door into this class.
 */
#[Layout('layouts.app')]
final class Wizard extends Component
{
    /**
     * In the query string so a full page reload resumes the same draft.
     * Typed as a string rather than ?int on purpose: a query parameter arrives
     * as text, and an int-typed Livewire property throws on hydration when the
     * parameter is absent or blank.
     */
    #[Url(as: 'application')]
    public string $applicationId = '';

    public int $step = WizardStep::FIRST;

    // --- Step 1, Basic Information ----------------------------------------
    // Property names are the COLUMN names. Not a style choice: the Action
    // throws its ValidationException keyed by column, and Livewire matches
    // error-bag keys to property names, so `@error('first_name')` beside the
    // input only lights up if the two spellings agree.

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $nationality = 'CM';

    public string $place_of_birth = '';

    public string $state_of_origin = '';

    public string $religion = '';

    public string $blood_group = '';

    public string $genotype = '';

    // --- Step 2, Academic Details -----------------------------------------

    public string $academic_year_id = '';

    public string $admission_term_id = '';

    public string $school_section_id = '';

    public string $class_level_id = '';

    public string $stream_id = '';

    public string $category = '';

    public string $admission_date = '';

    public string $proposed_roll_number = '';

    // --- Step 3, Parent / Guardian ----------------------------------------

    /** @var array<int, array<string, mixed>> */
    public array $guardians = [];

    // --- Step 4, Other Information ----------------------------------------

    public string $previous_school_name = '';

    public string $last_class_completed = '';

    public string $year_completed = '';

    public string $reason_for_leaving = '';

    public string $special_information = '';

    // --- Step 5, Documents & Review ---------------------------------------

    /**
     * 6.3 step 4: the class GROUP is chosen here, at enrolment, because the
     * application only ever named a class LEVEL. Capacity is checked under
     * lock inside EnrollStudent, not guessed at in this dropdown.
     */
    public string $class_group_id = '';

    public string $rejection_reason = '';

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        if ($this->applicationId !== '') {
            $this->loadApplication();
        }

        if ($this->guardians === []) {
            $this->guardians = [self::blankGuardian()];
        }
    }

    /** Save the current step and advance. */
    public function next(SaveApplicationStep $saveStep): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $step = WizardStep::from($this->step);

        // No try/catch. A ValidationException raised inside the Action is
        // exactly what Livewire's validation support is for: it lands in the
        // error bag keyed by column, which is why the properties above are
        // named after columns.
        $saved = $saveStep->handle($step, $this->payloadFor($step), $this->application());

        $this->applicationId = (string) $saved->getKey();
        // Staying put on the last step rather than running off the end.
        $this->step = ($step->next() ?? $step)->value;
        $this->statusMessage = __('opes.admissions_screen.draft_saved');
    }

    /**
     * 11.4: "Back never loses entered data." Nothing is saved and nothing is
     * cleared - the properties still hold what the operator typed.
     */
    public function back(): void
    {
        $this->statusMessage = '';

        $step = WizardStep::from($this->step);
        $this->step = ($step->previous() ?? $step)->value;
    }

    public function addGuardian(): void
    {
        $this->guardians[] = self::blankGuardian();
    }

    public function removeGuardian(int $index): void
    {
        unset($this->guardians[$index]);

        // Re-indexed because the position column is dense and the blade binds
        // on the array index; a hole would bind step 3's second guardian to
        // `guardians.2` and the Action would write it at position 2.
        $this->guardians = array_values($this->guardians);

        if ($this->guardians === []) {
            $this->guardians = [self::blankGuardian()];
        }
    }

    public function submit(SubmitApplication $submitApplication): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $application = $this->application();

        if ($application === null) {
            return;
        }

        $submitted = $submitApplication->handle($application);

        $this->statusMessage = __('opes.admissions_screen.submitted_message', [
            'number' => (string) $submitted->application_no,
        ]);
    }

    /** Convert on confirm - the last thing the wizard does. */
    public function confirm(ConvertApplication $convertApplication): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $application = $this->application();

        if ($application === null) {
            return;
        }

        if ($this->class_group_id === '') {
            throw ValidationException::withMessages([
                'class_group_id' => __('opes.admissions_screen.errors.class_group_required'),
            ]);
        }

        try {
            $convertApplication->handle($application, (int) $this->class_group_id);
        } catch (DomainException) {
            // The already-converted guard. Reported to the operator as a
            // refusal rather than a 500: pressing Confirm twice is an ordinary
            // human action, not an exceptional one.
            throw ValidationException::withMessages([
                'class_group_id' => __('opes.admissions_screen.enrolled_notice'),
            ]);
        }

        $this->statusMessage = __('opes.admissions_screen.converted_message');
    }

    public function reject(RejectApplication $rejectApplication): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $application = $this->application();

        if ($application === null) {
            return;
        }

        $rejected = $rejectApplication->handle($application, $this->rejection_reason);

        $this->statusMessage = __('opes.admissions_screen.rejected_message', [
            'date' => (string) $rejected->purge_due_on?->toDateString(),
        ]);
    }

    public function render(): mixed
    {
        $application = $this->application();

        return view('livewire.admissions.wizard', [
            'application' => $application,
            'steps' => WizardStep::all(),
            'currentStep' => WizardStep::from($this->step),
            'relationships' => ApplicantRelationship::cases(),
            'academicYears' => $this->options('academic_years', 'code'),
            'terms' => $this->termOptions(),
            'sections' => $this->options('school_sections', 'name'),
            'classLevels' => $this->options('class_levels', 'name'),
            'streams' => $this->options('streams', 'name'),
            'classGroups' => $this->classGroupOptions(),
            'previewNumber' => $application === null
                ? null
                : app(SubmitApplication::class)->previewNumber($application),
        ]);
    }

    // ---------------------------------------------------------------- state

    private function application(): ?AdmissionApplication
    {
        if ($this->applicationId === '') {
            return null;
        }

        /** @var AdmissionApplication|null $application */
        $application = AdmissionApplication::query()->find((int) $this->applicationId);

        return $application;
    }

    private function loadApplication(): void
    {
        $application = $this->application();

        if ($application === null) {
            // A stale or invented id in the query string: start a fresh draft
            // rather than 404, because the operator did not ask for a
            // particular record - they asked for the wizard.
            $this->applicationId = '';

            return;
        }

        foreach ([
            'first_name', 'middle_name', 'last_name', 'gender', 'nationality',
            'place_of_birth', 'state_of_origin', 'religion', 'blood_group', 'genotype',
            'category', 'previous_school_name', 'last_class_completed',
            'reason_for_leaving', 'special_information',
        ] as $column) {
            $this->{$column} = (string) ($application->{$column} ?? '');
        }

        foreach ([
            'academic_year_id', 'admission_term_id', 'school_section_id',
            'class_level_id', 'stream_id', 'proposed_roll_number', 'year_completed',
        ] as $column) {
            $value = $application->{$column};
            $this->{$column} = $value === null ? '' : (string) $value;
        }

        $this->date_of_birth = $application->date_of_birth?->toDateString() ?? '';
        $this->admission_date = $application->admission_date?->toDateString() ?? '';

        $this->guardians = array_map(
            static fn (\App\Modules\Admissions\Models\AdmissionApplicationGuardian $row): array => [
                'title' => (string) ($row->title ?? ''),
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'gender' => $row->gender,
                'date_of_birth' => $row->date_of_birth?->toDateString() ?? '',
                'relationship' => $row->relationship->value,
                'relationship_other' => (string) ($row->relationship_other ?? ''),
                'is_primary' => $row->is_primary,
                'id_type' => (string) ($row->id_type ?? ''),
                'id_number' => (string) ($row->id_number ?? ''),
                'occupation' => (string) ($row->occupation ?? ''),
                'employer' => (string) ($row->employer ?? ''),
                'phone' => $row->phone,
                'alternative_phone' => (string) ($row->alternative_phone ?? ''),
                'email' => (string) ($row->email ?? ''),
                'address_line' => (string) ($row->address_line ?? ''),
                'city' => (string) ($row->city ?? ''),
                'region' => (string) ($row->region ?? ''),
                'language' => $row->language,
                'has_custody' => $row->has_custody,
                'receives_reports' => $row->receives_reports,
                'receives_invoices' => $row->receives_invoices,
                'is_emergency_contact' => $row->is_emergency_contact,
                'is_authorised_for_pickup' => $row->is_authorised_for_pickup,
                'is_fee_payer' => $row->is_fee_payer,
            ],
            $application->guardians()->orderBy('position')->get()->all(),
        );

        // The ROW decides where the operator resumes, not the URL and not a
        // remembered client-side value. That is the whole point of 6.2's
        // "a power cut loses at most one step".
        $this->step = max(WizardStep::FIRST, min(WizardStep::LAST, $application->current_step));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(WizardStep $step): array
    {
        return match ($step) {
            WizardStep::BasicInformation => [
                'first_name' => $this->first_name,
                'middle_name' => $this->nullify($this->middle_name),
                'last_name' => $this->last_name,
                'date_of_birth' => $this->nullify($this->date_of_birth),
                'gender' => $this->gender,
                'nationality' => $this->nationality,
                'place_of_birth' => $this->nullify($this->place_of_birth),
                'state_of_origin' => $this->nullify($this->state_of_origin),
                'religion' => $this->nullify($this->religion),
                'blood_group' => $this->nullify($this->blood_group),
                'genotype' => $this->nullify($this->genotype),
            ],
            WizardStep::AcademicDetails => [
                'academic_year_id' => $this->intOrNull($this->academic_year_id),
                'admission_term_id' => $this->intOrNull($this->admission_term_id),
                'school_section_id' => $this->intOrNull($this->school_section_id),
                'class_level_id' => $this->intOrNull($this->class_level_id),
                'stream_id' => $this->intOrNull($this->stream_id),
                'category' => $this->nullify($this->category),
                'admission_date' => $this->nullify($this->admission_date),
                'proposed_roll_number' => $this->intOrNull($this->proposed_roll_number),
            ],
            WizardStep::ParentGuardian => ['guardians' => $this->guardians],
            WizardStep::OtherInformation => [
                'previous_school_name' => $this->nullify($this->previous_school_name),
                'last_class_completed' => $this->nullify($this->last_class_completed),
                'year_completed' => $this->intOrNull($this->year_completed),
                'reason_for_leaving' => $this->nullify($this->reason_for_leaving),
                'special_information' => $this->nullify($this->special_information),
            ],
            WizardStep::DocumentsReview => [],
        };
    }

    private function nullify(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function intOrNull(string $value): ?int
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : (int) $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function blankGuardian(): array
    {
        return [
            'title' => '',
            'first_name' => '',
            'last_name' => '',
            'gender' => '',
            'date_of_birth' => '',
            'relationship' => '',
            'relationship_other' => '',
            // The first guardian entered is the primary by default: 7.2
            // requires exactly one, and defaulting to none means every
            // operator meets the same error on their first attempt.
            'is_primary' => true,
            'id_type' => '',
            'id_number' => '',
            'occupation' => '',
            'employer' => '',
            'phone' => '',
            'alternative_phone' => '',
            'email' => '',
            'address_line' => '',
            'city' => '',
            'region' => '',
            'language' => 'en',
            'has_custody' => true,
            'receives_reports' => true,
            'receives_invoices' => true,
            'is_emergency_contact' => true,
            'is_authorised_for_pickup' => true,
            'is_fee_payer' => true,
        ];
    }

    // -------------------------------------------------------------- options

    /**
     * Reference data is read through the query builder, never through
     * Academics' Eloquent models: tests/Architecture/ModuleBoundaryTest.php
     * forbids this module from touching another module's Models and says the
     * rule has no exceptions.
     *
     * @return array<int, string>
     */
    private function options(string $table, string $labelColumn): array
    {
        /** @var array<int, string> $rows */
        $rows = DB::table($table)
            ->orderBy($labelColumn)
            ->pluck($labelColumn, 'id')
            ->map(static fn (mixed $label): string => (string) $label)
            ->all();

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function termOptions(): array
    {
        $query = DB::table('assessment_periods')->orderBy('name');

        if ($this->academic_year_id !== '') {
            $query->where('academic_year_id', '=', (int) $this->academic_year_id);
        }

        /** @var array<int, string> $rows */
        $rows = $query->pluck('name', 'id')->map(static fn (mixed $l): string => (string) $l)->all();

        return $rows;
    }

    /**
     * Only groups in the applied-for academic year, and only where the level
     * matches - offering an operator a group from another year would produce
     * an enrolment EnrollStudent then refuses at the very last moment.
     *
     * @return array<int, string>
     */
    private function classGroupOptions(): array
    {
        if ($this->academic_year_id === '') {
            return [];
        }

        $query = DB::table('class_groups')
            ->where('academic_year_id', '=', (int) $this->academic_year_id)
            ->orderBy('name');

        if ($this->class_level_id !== '') {
            $query->where('class_level_id', '=', (int) $this->class_level_id);
        }

        /** @var array<int, string> $rows */
        $rows = $query->pluck('name', 'id')->map(static fn (mixed $l): string => (string) $l)->all();

        return $rows;
    }

    /** True once the row can no longer be edited by the wizard (6.2). */
    public function isLocked(): bool
    {
        $application = $this->application();

        return $application !== null && ! $application->status->isEditable();
    }

    public function isEnrolled(): bool
    {
        return $this->application()?->status === ApplicationStatus::Enrolled;
    }
}
