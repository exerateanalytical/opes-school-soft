<?php

declare(strict_types=1);

namespace App\Modules\Academics\Livewire\Settings;

use App\Modules\Academics\Actions\CreateAcademicYear;
use App\Modules\Academics\Actions\CreateDepartment;
use App\Modules\Academics\Actions\CreateHouse;
use App\Modules\Academics\Actions\CreateRoom;
use App\Modules\Academics\Actions\CreateSchoolSection;
use App\Modules\Academics\Actions\DefineTermStructure;
use App\Modules\Academics\Actions\SetAcademicYearStatus;
use App\Modules\Academics\Actions\SetCurrentAcademicYear;
use App\Modules\Academics\Domain\AcademicYearStatus;
use App\Modules\Academics\Domain\AssessmentPeriodType;
use App\Modules\Academics\Domain\EducationLevel;
use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Academics\Domain\Track;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Academics\Models\Department;
use App\Modules\Academics\Models\House;
use App\Modules\Academics\Models\Room;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Subject;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Academic Settings (frontend images/accademic setting.png): the current
 * session card, the create-year form, and term-structure management.
 *
 * Authorisation is checked in mount() AND in every mutating method - the
 * route's `can:academics.manage` middleware is not the only door into a
 * Livewire component (same reasoning as Identity\Livewire\Users\Form).
 *
 * Domain exceptions from the Actions (gapless-contiguity, duplicate term
 * structure, out-of-year terms) surface as inline validation errors, never as
 * a 500: the operator typing 2027-09-02 instead of 2027-09-01 made a data
 * mistake, not a system error.
 */
#[Layout('layouts.app')]
final class AcademicSettings extends Component
{
    // ── Create-year form ────────────────────────────────────────────────
    public string $code = '';

    public string $name = '';

    public string $startsOn = '';

    public string $endsOn = '';

    // ── Term-structure form ─────────────────────────────────────────────
    public int $termCount = 3;

    /** @var list<array{starts_on: string, ends_on: string}> */
    public array $termDates = [];

    // ── Department form ─────────────────────────────────────────────────
    public bool $showDepartmentForm = false;

    public string $departmentCode = '';

    public string $departmentName = '';

    public string $departmentNameFr = '';

    // ── House form ───────────────────────────────────────────────────────
    public bool $showHouseForm = false;

    public string $houseCode = '';

    public string $houseName = '';

    public string $houseColour = '#2563eb';

    // ── Room form ────────────────────────────────────────────────────────
    public bool $showRoomForm = false;

    public string $roomCode = '';

    public string $roomName = '';

    public string $roomCapacity = '';

    public string $roomBuilding = '';

    public string $roomType = 'classroom';

    // ── School section form ────────────────────────────────────────────
    public bool $showSectionForm = false;

    public string $sectionEducationLevel = '';

    public string $sectionTrack = '';

    public string $sectionSubSystem = '';

    public string $sectionName = '';

    public string $sectionNameFr = '';

    public string $sectionMatriculeFormat = '';

    public string $sectionDisplayOrder = '0';

    public function mount(): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->syncTermRows();
    }

    public function updatedTermCount(): void
    {
        $this->syncTermRows();
    }

    /**
     * Keep exactly $termCount date rows, preserving anything already typed.
     * The value arrives over the wire as a string, so it is normalised (and
     * clamped to the only shapes DefineTermStructure accepts) here rather
     * than trusted.
     */
    private function syncTermRows(): void
    {
        $count = in_array($this->termCount, [2, 3], true) ? $this->termCount : 3;
        $this->termCount = $count;

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'starts_on' => $this->termDates[$i]['starts_on'] ?? '',
                'ends_on' => $this->termDates[$i]['ends_on'] ?? '',
            ];
        }

        $this->termDates = $rows;
    }

    public function createYear(CreateAcademicYear $createAcademicYear): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:32', 'unique:academic_years,code'],
            'name' => ['required', 'string', 'max:160'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['required', 'date', 'after:startsOn'],
        ]);

        try {
            $createAcademicYear->handle(
                code: $validated['code'],
                name: $validated['name'],
                startsOn: $validated['startsOn'],
                endsOn: $validated['endsOn'],
                actor: $this->actor(),
            );
        } catch (DomainException $exception) {
            // The gapless-contiguity rejection lands here. Attached to the
            // start-date field because that is the value the message names.
            $this->addError('startsOn', $exception->getMessage());

            return;
        }

        $this->reset(['code', 'name', 'startsOn', 'endsOn']);
        session()->flash('status', __('opes.academics.year_created'));
    }

    public function setCurrent(int $academicYearId, SetCurrentAcademicYear $setCurrentAcademicYear): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $setCurrentAcademicYear->handle($academicYearId, $this->actor());

        session()->flash('status', __('opes.academics.current_set'));
    }

    /**
     * Moves a year through planned -> active -> closed. Distinct from
     * setCurrent(): `status` gates whether a year accepts writes, `is_current`
     * is which one the UI displays by default (SetAcademicYearStatus docblock).
     */
    public function setStatus(int $academicYearId, string $status, SetAcademicYearStatus $setAcademicYearStatus): void
    {
        Gate::authorize(SetAcademicYearStatus::PERMISSION);

        $setAcademicYearStatus->handle($academicYearId, $status, $this->actor());

        session()->flash('status', __('opes.academics.status_updated'));
    }

    public function toggleDepartmentForm(): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->showDepartmentForm = ! $this->showDepartmentForm;

        if ($this->showDepartmentForm) {
            $this->reset(['departmentCode', 'departmentName', 'departmentNameFr']);
            $this->resetErrorBag();
        }
    }

    public function saveDepartment(CreateDepartment $createDepartment): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $validated = $this->validate([
            'departmentCode' => ['required', 'string', 'max:32'],
            'departmentName' => ['required', 'string', 'max:160'],
            'departmentNameFr' => ['nullable', 'string', 'max:160'],
        ], [], [
            'departmentCode' => 'code',
            'departmentName' => 'name',
            'departmentNameFr' => 'name_fr',
        ]);

        try {
            $createDepartment->handle(
                code: $validated['departmentCode'],
                name: $validated['departmentName'],
                nameFr: $validated['departmentNameFr'] === '' ? null : $validated['departmentNameFr'],
            );
        } catch (ValidationException $exception) {
            $this->addError('departmentCode', collect($exception->errors())->collapse()->first() ?? $exception->getMessage());

            return;
        }

        $this->reset(['showDepartmentForm', 'departmentCode', 'departmentName', 'departmentNameFr']);
        session()->flash('status', __('opes.academics.department_created'));
    }

    public function toggleHouseForm(): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->showHouseForm = ! $this->showHouseForm;

        if ($this->showHouseForm) {
            $this->reset(['houseCode', 'houseName', 'houseColour']);
            $this->houseColour = '#2563eb';
            $this->resetErrorBag();
        }
    }

    public function saveHouse(CreateHouse $createHouse): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $validated = $this->validate([
            'houseCode' => ['required', 'string', 'max:32'],
            'houseName' => ['required', 'string', 'max:160'],
            'houseColour' => ['required', 'string', 'max:16'],
        ], [], [
            'houseCode' => 'code',
            'houseName' => 'name',
            'houseColour' => 'colour',
        ]);

        try {
            $createHouse->handle(
                code: $validated['houseCode'],
                name: $validated['houseName'],
                colour: $validated['houseColour'],
            );
        } catch (ValidationException $exception) {
            $this->addError('houseCode', collect($exception->errors())->collapse()->first() ?? $exception->getMessage());

            return;
        }

        $this->reset(['showHouseForm', 'houseCode', 'houseName']);
        $this->houseColour = '#2563eb';
        session()->flash('status', __('opes.academics.house_created'));
    }

    public function toggleRoomForm(): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->showRoomForm = ! $this->showRoomForm;

        if ($this->showRoomForm) {
            $this->reset(['roomCode', 'roomName', 'roomCapacity', 'roomBuilding']);
            $this->roomType = 'classroom';
            $this->resetErrorBag();
        }
    }

    public function saveRoom(CreateRoom $createRoom): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $validated = $this->validate([
            'roomCode' => ['required', 'string', 'max:32'],
            'roomName' => ['required', 'string', 'max:160'],
            'roomCapacity' => ['required', 'integer', 'min:1', 'max:2000'],
            'roomBuilding' => ['nullable', 'string', 'max:160'],
            'roomType' => ['required', 'string', 'in:classroom,lab,hall,office,other'],
        ], [], [
            'roomCode' => 'code',
            'roomName' => 'name',
        ]);

        try {
            $createRoom->handle(
                code: $validated['roomCode'],
                name: $validated['roomName'],
                capacity: (int) $validated['roomCapacity'],
                building: $validated['roomBuilding'] === '' ? null : $validated['roomBuilding'],
                type: $validated['roomType'],
            );
        } catch (ValidationException $exception) {
            $this->addError('roomCode', collect($exception->errors())->collapse()->first() ?? $exception->getMessage());

            return;
        } catch (InvalidArgumentException $exception) {
            $this->addError('roomCapacity', $exception->getMessage());

            return;
        }

        $this->reset(['showRoomForm', 'roomCode', 'roomName', 'roomCapacity', 'roomBuilding']);
        $this->roomType = 'classroom';
        session()->flash('status', __('opes.academics.room_created'));
    }

    public function toggleSectionForm(): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->showSectionForm = ! $this->showSectionForm;

        if ($this->showSectionForm) {
            $this->reset([
                'sectionEducationLevel', 'sectionTrack', 'sectionSubSystem',
                'sectionName', 'sectionNameFr', 'sectionMatriculeFormat',
            ]);
            $this->sectionDisplayOrder = '0';
            $this->resetErrorBag();
        }
    }

    public function saveSection(CreateSchoolSection $createSchoolSection): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $validated = $this->validate([
            'sectionEducationLevel' => ['required', Rule::enum(EducationLevel::class)],
            'sectionTrack' => ['required', Rule::enum(Track::class)],
            'sectionSubSystem' => ['required', Rule::enum(SubSystem::class)],
            'sectionName' => ['required', 'string', 'max:160'],
            'sectionNameFr' => ['required', 'string', 'max:160'],
            'sectionMatriculeFormat' => ['required', 'string', 'max:64'],
            'sectionDisplayOrder' => ['required', 'integer', 'min:0'],
        ], [], [
            'sectionEducationLevel' => 'education level',
            'sectionTrack' => 'track',
            'sectionSubSystem' => 'sub-system',
            'sectionName' => 'name',
            'sectionNameFr' => 'name_fr',
            'sectionMatriculeFormat' => 'matricule format',
        ]);

        try {
            $createSchoolSection->handle(
                educationLevel: EducationLevel::from($validated['sectionEducationLevel']),
                track: Track::from($validated['sectionTrack']),
                subSystem: SubSystem::from($validated['sectionSubSystem']),
                name: $validated['sectionName'],
                nameFr: $validated['sectionNameFr'],
                matriculeFormat: $validated['sectionMatriculeFormat'],
                displayOrder: (int) $validated['sectionDisplayOrder'],
            );
        } catch (ValidationException $exception) {
            $this->addError('sectionName', collect($exception->errors())->collapse()->first() ?? $exception->getMessage());

            return;
        }

        $this->reset([
            'showSectionForm', 'sectionEducationLevel', 'sectionTrack', 'sectionSubSystem',
            'sectionName', 'sectionNameFr', 'sectionMatriculeFormat',
        ]);
        $this->sectionDisplayOrder = '0';
        session()->flash('status', __('opes.academics.section_created'));
    }

    public function saveTerms(DefineTermStructure $defineTermStructure): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $year = $this->displayedYear();

        if ($year === null) {
            $this->addError('termDates', __('opes.academics.terms_need_year'));

            return;
        }

        $this->validate([
            'termCount' => ['required', 'integer', 'in:2,3'],
            'termDates' => ['required', 'array'],
            'termDates.*.starts_on' => ['required', 'date'],
            'termDates.*.ends_on' => ['required', 'date'],
        ]);

        try {
            $defineTermStructure->handle(
                academicYearId: (int) $year->getKey(),
                termCount: $this->termCount,
                termDates: $this->termDates,
                actor: $this->actor(),
            );
        } catch (DomainException $exception) {
            // Contiguity/overlap/out-of-year rejections read as one inline
            // error under the term grid, phrased by the domain itself.
            $this->addError('termDates', $exception->getMessage());

            return;
        }

        session()->flash('status', __('opes.academics.terms_saved'));
    }

    /**
     * The session the page displays and edits: the current year when one is
     * set, otherwise the most recently starting year (a school that has just
     * created its first, still-planned year needs to see it here).
     */
    private function displayedYear(): ?AcademicYear
    {
        return AcademicYear::query()->where('is_current', true)->first()
            ?? AcademicYear::query()->orderByDesc('starts_on')->first();
    }

    private function actor(): Actor
    {
        $user = auth()->user();

        return $user?->toAuditActor() ?? Actor::system();
    }

    public function render(): mixed
    {
        $year = $this->displayedYear();

        $terms = $year === null
            ? collect()
            : AssessmentPeriod::query()
                ->where('academic_year_id', $year->getKey())
                ->where('type', AssessmentPeriodType::Term)
                ->orderBy('order_index')
                ->get();

        // "Current term" is DERIVED from real dates - the term of the current
        // year whose range contains today - because Phase 1 has no stored
        // current-term flag and no set-current-term Action to wire a control
        // to. Displayed, not editable.
        $today = now()->startOfDay();
        $currentTerm = ($year !== null && $year->is_current)
            ? $terms->first(
                fn (AssessmentPeriod $term): bool => $term->starts_on->lessThanOrEqualTo($today)
                    && $term->ends_on->greaterThanOrEqualTo($today)
            )
            : null;

        return view('livewire.academics.settings.academic-settings', [
            'year' => $year,
            'otherYears' => AcademicYear::query()
                ->orderByDesc('starts_on')
                ->get()
                ->reject(fn (AcademicYear $candidate): bool => $year !== null && $candidate->is($year))
                ->values(),
            'terms' => $terms,
            'currentTerm' => $currentTerm,
            'departments' => Department::query()->orderBy('name')->get(),
            'houses' => House::query()->orderBy('name')->get(),
            'rooms' => Room::query()->orderBy('name')->get(),
            'sections' => SchoolSection::query()->orderBy('display_order')->get(),
            'educationLevels' => EducationLevel::cases(),
            'tracks' => Track::cases(),
            'subSystems' => SubSystem::cases(),
            'yearStatuses' => AcademicYearStatus::cases(),
            // Real counts only (fidelity rule): the mockup's core/elective
            // split has no backing field in Phase 1, so it is not shown.
            'totalSubjects' => Subject::query()->count(),
            'activeSubjects' => Subject::query()->where('is_active', true)->count(),
        ]);
    }
}
