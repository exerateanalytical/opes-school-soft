<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Discipline;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Welfare\Actions\AcknowledgeSanction;
use App\Modules\Welfare\Actions\ApplySanction;
use App\Modules\Welfare\Actions\ResolveDisciplineCase;
use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Domain\SanctionType;
use App\Modules\Welfare\Models\DisciplineCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use stdClass;

/**
 * One case's file at /welfare/discipline/{case}: the incident, its
 * lifecycle, its sanctions and the guardian-acknowledgement state, plus the
 * SanctionLadder's ADVISORY suggestion rendered as a pre-selected default
 * the Discipline Master is free to override (design doc: "advisory only,
 * never automatic").
 */
#[Layout('layouts.app')]
final class CaseShow extends Component
{
    public int $caseId;

    // ── Apply Sanction form ─────────────────────────────────────────────
    public bool $showSanctionForm = false;

    public string $sanctionType = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $notes = '';

    // ── Resolve form ────────────────────────────────────────────────────
    public bool $showResolveForm = false;

    public string $resolveOutcome = 'resolved';

    public string $resolutionNote = '';

    public function mount(int $case): void
    {
        Gate::authorize(Permission::DisciplineView->value);

        $this->caseId = $case;

        // 404 early rather than rendering an empty file.
        DisciplineCase::query()->findOrFail($case);
    }

    public function toggleSanctionForm(): void
    {
        Gate::authorize(Permission::DisciplineManage->value);

        $this->showSanctionForm = ! $this->showSanctionForm;

        if ($this->showSanctionForm) {
            // The ladder's suggestion is the form's DEFAULT, nothing more.
            $this->sanctionType = app(ApplySanction::class)
                ->suggestionFor($this->caseId)->value;
            $this->startsOn = now()->toDateString();
        }
    }

    public function applySanction(): void
    {
        Gate::authorize(Permission::DisciplineManage->value);

        $this->validate([
            'sanctionType' => ['required', 'string'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date'],
        ]);

        $type = SanctionType::tryFrom($this->sanctionType);

        if ($type === null) {
            $this->addError('sanctionType', __('discipline.unknown_sanction_type'));

            return;
        }

        app(ApplySanction::class)->handle(
            caseId: $this->caseId,
            type: $type,
            startsOn: $this->startsOn,
            endsOn: $this->endsOn === '' ? null : $this->endsOn,
            notes: $this->notes === '' ? null : $this->notes,
        );

        $this->reset(['showSanctionForm', 'sanctionType', 'startsOn', 'endsOn', 'notes']);
    }

    public function markUnderInvestigation(): void
    {
        Gate::authorize(Permission::DisciplineManage->value);

        app(ResolveDisciplineCase::class)->handle(
            $this->caseId,
            DisciplineCaseStatus::UnderInvestigation,
        );
    }

    public function resolveCase(): void
    {
        Gate::authorize(Permission::DisciplineManage->value);

        $this->validate([
            'resolveOutcome' => ['required', 'in:resolved,dismissed'],
            'resolutionNote' => ['required', 'string', 'min:3'],
        ], [], [
            'resolutionNote' => __('discipline.field_resolution_note'),
        ]);

        app(ResolveDisciplineCase::class)->handle(
            $this->caseId,
            DisciplineCaseStatus::from($this->resolveOutcome),
            $this->resolutionNote,
        );

        $this->reset(['showResolveForm', 'resolutionNote']);
    }

    public function acknowledgeSanction(int $sanctionId): void
    {
        Gate::authorize(Permission::DisciplineManage->value);

        // Guard against a forged sanction id from another case's file.
        $belongs = DB::table('discipline_sanctions')
            ->where('id', $sanctionId)
            ->where('discipline_case_id', $this->caseId)
            ->exists();

        if (! $belongs) {
            $this->addError('sanction', __('discipline.sanction_not_on_case'));

            return;
        }

        app(AcknowledgeSanction::class)->handle($sanctionId);
    }

    private function studentRow(int $studentId): ?stdClass
    {
        $row = DB::table('students')
            ->where('id', $studentId)
            ->first(['id', 'first_name', 'last_name', 'matricule', 'admission_no']);

        return $row instanceof stdClass ? $row : null;
    }

    private function classGroupName(?int $enrollmentId): ?string
    {
        if ($enrollmentId === null) {
            return null;
        }

        $name = DB::table('enrollment_segments as seg')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('seg.enrollment_id', $enrollmentId)
            ->whereNull('seg.ends_on')
            ->value('cg.name');

        return $name === null ? null : (string) $name;
    }

    /**
     * The student's live guardian links - who is entitled to be told about
     * this case at all, and how to reach them. Guardian visibility of the
     * narrative itself is still governed by the case's `visibility` flag
     * (07-students §row 20).
     *
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function guardians(int $studentId): \Illuminate\Support\Collection
    {
        return DB::table('student_guardians as sg')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->where('sg.student_id', $studentId)
            ->whereNull('sg.valid_to')
            ->orderByDesc('sg.is_primary')
            ->limit(20)
            ->select([
                'sg.id', 'sg.relationship', 'sg.is_primary', 'sg.has_custody',
                'sg.is_emergency_contact', 'sg.receives_reports',
                'g.first_name', 'g.last_name', 'g.phone', 'g.email',
            ])
            ->get();
    }

    /**
     * This student's other discipline entries, newest first - the
     * cross-year history the sanction ladder is graded against
     * (07-students §133).
     *
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function studentHistory(int $studentId): \Illuminate\Support\Collection
    {
        return DB::table('discipline_cases as c')
            ->join('discipline_categories as cat', 'cat.id', '=', 'c.discipline_category_id')
            ->where('c.student_id', $studentId)
            ->where('c.id', '!=', $this->caseId)
            ->orderByDesc('c.occurred_on')->orderByDesc('c.id')
            ->limit(50)
            ->select([
                'c.id', 'c.occurred_on', 'c.status', 'c.is_positive',
                'cat.name as category_name', 'cat.severity',
            ])
            ->selectSub(
                DB::table('discipline_sanctions')
                    ->whereColumn('discipline_case_id', 'c.id')
                    ->selectRaw('COUNT(*)'),
                'sanction_count'
            )
            ->get();
    }

    /**
     * Names of the users who applied each sanction, keyed by sanction id -
     * the audit half of the sanctions table.
     *
     * @return array<int, string>
     */
    private function sanctionAuthors(): array
    {
        /** @var \Illuminate\Support\Collection<int, stdClass> $rows */
        $rows = DB::table('discipline_sanctions as s')
            ->leftJoin('users as u', 'u.id', '=', 's.applied_by')
            ->where('s.discipline_case_id', $this->caseId)
            ->get(['s.id', 'u.name']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->id] = (string) ($row->name ?? '—');
        }

        return $out;
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $name = DB::table('users')->where('id', $userId)->value('name');

        return $name === null ? null : (string) $name;
    }

    public function render(): mixed
    {
        /** @var DisciplineCase $case */
        $case = DisciplineCase::query()
            ->with(['category', 'sanctions' => fn ($query) => $query->orderBy('starts_on')])
            ->findOrFail($this->caseId);

        $canManage = Gate::allows(Permission::DisciplineManage->value)
            && ! $case->status->isTerminal();

        return view('livewire.welfare.discipline.case-show', [
            'case' => $case,
            'student' => $this->studentRow($case->student_id),
            'classGroupName' => $this->classGroupName($case->enrollment_id),
            'reporterName' => $this->userName($case->reported_by),
            'resolverName' => $this->userName($case->resolved_by),
            'guardians' => $this->guardians($case->student_id),
            'studentHistory' => $this->studentHistory($case->student_id),
            'sanctionAuthors' => $this->sanctionAuthors(),
            'canManage' => $canManage,
            'suggestion' => $canManage && ! $case->is_positive
                ? app(ApplySanction::class)->suggestionFor($this->caseId)
                : null,
            'sanctionTypes' => SanctionType::cases(),
        ]);
    }
}
