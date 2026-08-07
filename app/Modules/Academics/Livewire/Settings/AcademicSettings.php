<?php

declare(strict_types=1);

namespace App\Modules\Academics\Livewire\Settings;

use App\Modules\Academics\Actions\CreateAcademicYear;
use App\Modules\Academics\Actions\DefineTermStructure;
use App\Modules\Academics\Actions\SetCurrentAcademicYear;
use App\Modules\Academics\Domain\AssessmentPeriodType;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Academics\Models\Subject;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Gate;
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
            // Real counts only (fidelity rule): the mockup's core/elective
            // split has no backing field in Phase 1, so it is not shown.
            'totalSubjects' => Subject::query()->count(),
            'activeSubjects' => Subject::query()->where('is_active', true)->count(),
        ]);
    }
}
