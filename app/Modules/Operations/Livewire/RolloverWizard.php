<?php

declare(strict_types=1);

namespace App\Modules\Operations\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Operations\Actions\CreateBackup;
use App\Modules\Operations\Actions\Rollover\ArchiveLeaversStep;
use App\Modules\Operations\Actions\Rollover\CarryBalancesStep;
use App\Modules\Operations\Actions\Rollover\CopyAssessmentPeriodsStep;
use App\Modules\Operations\Actions\Rollover\CopyClassGroupsStep;
use App\Modules\Operations\Actions\Rollover\CopyFeeStructuresStep;
use App\Modules\Operations\Actions\Rollover\CopySubjectAllocationsStep;
use App\Modules\Operations\Actions\Rollover\CreateNewYearStep;
use App\Modules\Operations\Actions\Rollover\FlipActiveYearStep;
use App\Modules\Operations\Actions\Rollover\PreviewStep;
use App\Modules\Operations\Actions\Rollover\PromoteStudentsStep;
use App\Modules\Operations\Actions\Rollover\ReassignTeachersStep;
use App\Modules\Operations\Actions\Rollover\StartRolloverRun;
use App\Modules\Operations\Actions\Rollover\UndoRollover;
use App\Modules\Operations\Actions\VerifyBackup;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Models\RolloverRun;
use App\Modules\Students\Actions\RecordPromotionDecision;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use stdClass;
use Throwable;

/**
 * The year-rollover wizard screen (docs/specs/08-operations.md §6.2):
 * eleven stations (pre-flight + ten steps), each previewable before its
 * Apply, the whole run resumable and reversible.
 *
 * Conventions follow Admissions\Livewire\Wizard exactly:
 *
 *  - the RUN id rides in the query string, so a reload - a power cut, a
 *    closed laptop - re-mounts, finds the run and resumes at the step the
 *    ROW says it reached (§6.3 "resumable after a power cut");
 *  - validation and every guard live in the step Actions, not here; the
 *    component catches their DomainException and shows the refusal verbatim
 *    - those messages are the spec's own plain-language refusals;
 *  - authorisation is checked in mount() AND re-checked inside every step
 *    Action (a Livewire component can be reached without its route).
 *
 * Cross-module reads (academic years, enrollments, invoices, allocations)
 * go through DB::table only - tests/Architecture/ModuleBoundaryTest.php.
 * The step-6 decision grid WRITES through the one Students door,
 * RecordPromotionDecision; no other write leaves this component except via
 * the Operations step Actions.
 */
#[Layout('layouts.app')]
final class RolloverWizard extends Component
{
    /**
     * String, not ?int, for the same hydration reason as the Admissions
     * wizard: a query parameter arrives as text and an int-typed property
     * throws when it is absent or blank.
     */
    #[Url(as: 'run')]
    public string $runId = '';

    // --- Step 0, pre-flight ------------------------------------------------

    public string $fromYearId = '';

    public string $backupId = '';

    // --- Step 1, create the new year --------------------------------------

    public string $newYearCode = '';

    public string $newYearName = '';

    public string $newYearEndsOn = '';

    // --- Step 5, fee structures -------------------------------------------

    public string $upliftPercent = '0';

    // --- Step 6, promotion decision grid ----------------------------------

    /** @var array<int, array{decision: string, target: string}> */
    public array $decisions = [];

    // --- Step 7, per-debtor choices ---------------------------------------

    /** @var array<int, string> */
    public array $debtorChoices = [];

    // --- Step 9, teacher overrides (comma-separated user ids) -------------

    /** @var array<int, string> */
    public array $teacherOverrides = [];

    public string $statusMessage = '';

    public string $errorMessage = '';

    public function mount(): void
    {
        Gate::authorize(Permission::RolloverRun->value);

        if ($this->runId !== '' && $this->run() === null) {
            // A stale or invented id: start fresh rather than 404 - the
            // operator asked for the wizard, not a particular record.
            $this->runId = '';
        }
    }

    // ---------------------------------------------------------------- writes

    /**
     * Step 0: pre-flight + open (or resume) the run. Every check lives in
     * StartRolloverRun; a refusal surfaces verbatim.
     */
    public function start(StartRolloverRun $start): void
    {
        Gate::authorize(Permission::RolloverRun->value);
        $this->resetMessages();

        if ($this->fromYearId === '' || $this->backupId === '') {
            $this->errorMessage = (string) __('rollover.wizard.backup_hint');

            return;
        }

        try {
            $run = $start->handle((int) $this->fromYearId, (int) $this->backupId, $this->actor());

            $this->runId = (string) $run->getKey();
            $this->statusMessage = (string) __('rollover.wizard.started');
        } catch (DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    /** Convenience for step 0: take a backup and verify it, in one click. */
    public function takeBackup(CreateBackup $create, VerifyBackup $verify): void
    {
        Gate::authorize(Permission::BackupRun->value);
        $this->resetMessages();

        try {
            $backup = $verify->handle($create->handle());

            $this->backupId = (string) $backup->getKey();
            $this->statusMessage = (string) __('rollover.wizard.backup_done', ['id' => (string) $backup->getKey()]);
        } catch (Throwable $exception) {
            // CreateBackup shells out to mysqldump; any failure mode here is
            // an operator-facing refusal, not a 500.
            $this->errorMessage = $exception->getMessage();
        }
    }

    /** Resume the resumable run the screen offered. */
    public function resume(int $runId): void
    {
        Gate::authorize(Permission::RolloverRun->value);
        $this->resetMessages();

        if (RolloverRun::query()->whereKey($runId)->exists()) {
            $this->runId = (string) $runId;
        }
    }

    /** Apply the run's current step. Dispatch only - the Actions decide. */
    public function apply(): void
    {
        Gate::authorize(Permission::RolloverRun->value);
        $this->resetMessages();

        $run = $this->run();

        if ($run === null) {
            return;
        }

        $actor = $this->actor();

        try {
            match ($run->currentStep()) {
                RolloverStep::Preflight => null,
                RolloverStep::CreateNewYear => app(CreateNewYearStep::class)->handle(
                    $run,
                    trim($this->newYearCode),
                    trim($this->newYearName),
                    $actor,
                    trim($this->newYearEndsOn) === '' ? null : trim($this->newYearEndsOn),
                ),
                RolloverStep::CopyClassGroups => app(CopyClassGroupsStep::class)->handle($run, $actor),
                RolloverStep::CopySubjectAllocations => app(CopySubjectAllocationsStep::class)->handle($run, $actor),
                RolloverStep::CopyAssessmentPeriods => app(CopyAssessmentPeriodsStep::class)->handle($run, $actor),
                RolloverStep::CopyFeeStructures => app(CopyFeeStructuresStep::class)->handle(
                    $run,
                    $actor,
                    (int) round(((float) $this->upliftPercent) * 100),
                ),
                RolloverStep::PromoteStudents => app(PromoteStudentsStep::class)->handle((int) $run->getKey(), $actor),
                RolloverStep::CarryBalances => app(CarryBalancesStep::class)->handle(
                    (int) $run->getKey(),
                    array_map(static fn (string $choice): string => $choice, $this->debtorChoices),
                    $actor,
                ),
                RolloverStep::ArchiveLeavers => app(ArchiveLeaversStep::class)->handle((int) $run->getKey(), $actor),
                RolloverStep::ReassignTeachers => app(ReassignTeachersStep::class)->handle(
                    (int) $run->getKey(),
                    $this->parsedOverrides(),
                    $actor,
                ),
                RolloverStep::FlipActiveYear => app(FlipActiveYearStep::class)->handle($run, $actor),
            };

            $this->statusMessage = (string) __('rollover.wizard.applied');
        } catch (DomainException|ValidationException $exception) {
            $this->errorMessage = $this->firstMessage($exception);
        }
    }

    /**
     * Step 6 grid: record one enrolment's promotion decision through the
     * Students door. The rollover step itself refuses while any active
     * enrolment remains undecided.
     */
    public function saveDecision(int $enrollmentId, RecordPromotionDecision $record): void
    {
        Gate::authorize(Permission::RolloverRun->value);
        $this->resetMessages();

        $row = $this->decisions[$enrollmentId] ?? ['decision' => '', 'target' => ''];
        $target = trim($row['target']);

        try {
            $record->handle(
                enrollmentId: $enrollmentId,
                decision: $row['decision'],
                targetClassGroupKey: $target === '' ? null : $target,
                actor: $this->actor(),
            );

            unset($this->decisions[$enrollmentId]);
            $this->statusMessage = (string) __('rollover.wizard.decision_saved');
        } catch (DomainException|ValidationException $exception) {
            $this->errorMessage = $this->firstMessage($exception);
        }
    }

    public function undo(UndoRollover $undoAction): void
    {
        Gate::authorize(Permission::RolloverRun->value);
        $this->resetMessages();

        $run = $this->run();

        if ($run === null) {
            return;
        }

        try {
            $undoAction->handle($run, $this->actor());
            $this->statusMessage = (string) __('rollover.wizard.undone');
        } catch (DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    // ---------------------------------------------------------------- render

    public function render(PreviewStep $previewStep): View
    {
        $run = $this->run();
        $currentStep = $run?->currentStep();

        $preview = null;

        if ($run !== null && $currentStep !== null && $run->status()->isResumable()) {
            try {
                $preview = $previewStep->handle($run, $currentStep);
            } catch (DomainException) {
                $preview = null;
            }
        }

        return view('livewire.operations.rollover-wizard', [
            'run' => $run,
            'steps' => RolloverStep::cases(),
            'currentStep' => $currentStep,
            'preview' => $preview,
            'years' => $this->yearOptions(),
            'backups' => $this->backupOptions(),
            'resumable' => $run === null ? $this->resumableRun() : null,
            'fromYear' => $run === null ? null : $this->yearRow($run->academic_year_from_id),
            'toYear' => $run?->academic_year_to_id === null ? null : $this->yearRow($run->academic_year_to_id),
            'pendingDecisions' => $currentStep === RolloverStep::PromoteStudents ? $this->pendingDecisions($run) : [],
            'targetGroups' => $currentStep === RolloverStep::PromoteStudents ? $this->targetGroupOptions($run) : [],
            'debtors' => $currentStep === RolloverStep::CarryBalances ? $this->debtors($run) : [],
            'allocations' => $currentStep === RolloverStep::ReassignTeachers ? $this->newYearAllocations($run) : [],
            'canTakeBackup' => Gate::allows(Permission::BackupRun->value),
        ]);
    }

    // ---------------------------------------------------------------- state

    private function run(): ?RolloverRun
    {
        if ($this->runId === '') {
            return null;
        }

        /** @var RolloverRun|null $run */
        $run = RolloverRun::query()->find((int) $this->runId);

        return $run;
    }

    private function resumableRun(): ?RolloverRun
    {
        /** @var RolloverRun|null $run */
        $run = RolloverRun::query()
            ->whereIn('status', [RolloverRunStatus::Running->value, RolloverRunStatus::Failed->value])
            ->orderByDesc('id')
            ->first();

        return $run;
    }

    private function actor(): Actor
    {
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        return new Actor((int) $user->getAuthIdentifier(), (string) $user->getAttribute('name'));
    }

    private function resetMessages(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';
    }

    private function firstMessage(DomainException|ValidationException $exception): string
    {
        if ($exception instanceof ValidationException) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    return $message;
                }
            }
        }

        return $exception->getMessage();
    }

    /**
     * @return array<int, list<int>>
     */
    private function parsedOverrides(): array
    {
        $parsed = [];

        foreach ($this->teacherOverrides as $allocationId => $raw) {
            $ids = [];

            foreach (explode(',', $raw) as $piece) {
                $piece = trim($piece);

                if ($piece !== '' && ctype_digit($piece)) {
                    $ids[] = (int) $piece;
                }
            }

            if ($ids !== []) {
                $parsed[(int) $allocationId] = $ids;
            }
        }

        return $parsed;
    }

    // -------------------------------------------------------------- options

    /**
     * Outgoing-year candidates: any non-closed year, current first. Read via
     * DB::table - Academics owns the model.
     *
     * @return array<int, string>
     */
    private function yearOptions(): array
    {
        /** @var array<int, string> $rows */
        $rows = DB::table('academic_years')
            ->where('status', '!=', 'closed')
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->pluck('code', 'id')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        return $rows;
    }

    /**
     * Verified healthy backups, newest first (§6.2 step 0: no override).
     *
     * @return array<int, string>
     */
    private function backupOptions(): array
    {
        $options = [];

        foreach (Backup::query()
            ->where('status', 'healthy')
            ->whereNotNull('verified_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get() as $backup) {
            $options[(int) $backup->getKey()] = sprintf(
                '#%d — %s',
                (int) $backup->getKey(),
                (string) ($backup->completed_at?->toDateTimeString() ?? $backup->created_at?->toDateTimeString() ?? ''),
            );
        }

        return $options;
    }

    private function yearRow(int $id): ?stdClass
    {
        return DB::table('academic_years')->where('id', $id)->first();
    }

    /**
     * Step 6: active enrolments in the outgoing year that have no promotion
     * decision yet - the grid writes through RecordPromotionDecision.
     *
     * @return list<stdClass>
     */
    private function pendingDecisions(?RolloverRun $run): array
    {
        if ($run === null) {
            return [];
        }

        /** @var list<stdClass> $rows */
        $rows = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('class_groups as g', 'g.id', '=', 'e.class_group_id')
            ->where('e.academic_year_id', $run->academic_year_from_id)
            ->where('e.status', 'active')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('promotion_decisions as pd')
                    ->whereColumn('pd.enrollment_id', 'e.id');
            })
            ->orderBy('g.name')
            ->orderBy('s.last_name')
            ->get(['e.id', 's.first_name', 's.last_name', 'g.name as group_name'])
            ->all();

        return $rows;
    }

    /**
     * Step 6 destinations, in PromoteStudentsStep's documented "group:<name>"
     * form, resolved against the NEW year.
     *
     * @return list<string>
     */
    private function targetGroupOptions(?RolloverRun $run): array
    {
        if ($run === null || $run->academic_year_to_id === null) {
            return [];
        }

        /** @var list<string> $names */
        $names = DB::table('class_groups')
            ->where('academic_year_id', $run->academic_year_to_id)
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->unique()
            ->values()
            ->all();

        return $names;
    }

    /**
     * Step 7's "students still owing" list: outstanding per student on the
     * outgoing year's ISSUED invoices - the same terms CarryBalancesStep
     * re-derives authoritatively under its own lock (invoiced gross minus
     * live allocations, approved adjustments and issued credit notes). This
     * query only DRAWS the grid; a drift between the two surfaces as the
     * Action's own refusal naming the student, never as a silent skip.
     *
     * @return list<array{student_id: int, name: string, outstanding: int}>
     */
    private function debtors(?RolloverRun $run): array
    {
        if ($run === null) {
            return [];
        }

        $fromYearId = $run->academic_year_from_id;
        $outstanding = [];

        $invoiced = DB::table('invoices as i')
            ->join('invoice_lines as l', 'l.invoice_id', '=', 'i.id')
            ->where('i.academic_year_id', $fromYearId)
            ->where('i.status', 'issued')
            ->groupBy('i.student_id')
            ->select(['i.student_id', DB::raw('CAST(SUM(l.amount + l.tax_amount) AS SIGNED) as total')])
            ->get();

        foreach ($invoiced as $row) {
            $outstanding[(int) $row->student_id] = (int) $row->total;
        }

        $allocated = DB::table('payment_allocations as pa')
            ->join('invoices as i', 'i.id', '=', 'pa.invoice_id')
            ->whereNull('pa.reversed_at')
            ->where('i.academic_year_id', $fromYearId)
            ->where('i.status', 'issued')
            ->groupBy('i.student_id')
            ->select(['i.student_id', DB::raw('CAST(SUM(pa.amount) AS SIGNED) as total')])
            ->get();

        foreach ($allocated as $row) {
            $outstanding[(int) $row->student_id] = ($outstanding[(int) $row->student_id] ?? 0) - (int) $row->total;
        }

        if (Schema::hasTable('fee_adjustments')) {
            $adjusted = DB::table('fee_adjustments as fa')
                ->where('fa.status', 'approved')
                ->where('fa.academic_year_id', $fromYearId)
                ->groupBy('fa.student_id')
                ->select(['fa.student_id', DB::raw('CAST(SUM(fa.amount) AS SIGNED) as total')])
                ->get();

            foreach ($adjusted as $row) {
                $outstanding[(int) $row->student_id] = ($outstanding[(int) $row->student_id] ?? 0) - (int) $row->total;
            }
        }

        if (Schema::hasTable('credit_notes') && Schema::hasTable('credit_note_lines')) {
            $credited = DB::table('credit_notes as cn')
                ->join('credit_note_lines as cnl', 'cnl.credit_note_id', '=', 'cn.id')
                ->where('cn.status', 'issued')
                ->where('cn.academic_year_id', $fromYearId)
                ->groupBy('cn.student_id')
                ->select(['cn.student_id', DB::raw('CAST(SUM(cnl.amount + cnl.tax_amount) AS SIGNED) as total')])
                ->get();

            foreach ($credited as $row) {
                $outstanding[(int) $row->student_id] = ($outstanding[(int) $row->student_id] ?? 0) - (int) $row->total;
            }
        }

        $debtorIds = array_keys(array_filter($outstanding, static fn (int $net): bool => $net > 0));

        if ($debtorIds === []) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = DB::table('students')
            ->whereIn('id', $debtorIds)
            ->selectRaw("id, CONCAT(first_name, ' ', last_name) as full_name")
            ->pluck('full_name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        $rows = [];

        foreach ($debtorIds as $studentId) {
            $rows[] = [
                'student_id' => $studentId,
                'name' => $names[$studentId] ?? ('#'.$studentId),
                'outstanding' => $outstanding[$studentId],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Step 9's reassignment grid: the new year's active allocations with the
     * teachers they would inherit from the outgoing year's same-scope
     * allocation, departed (inactive) staff flagged.
     *
     * @return list<array{id: int, label: string, inherited: list<array{name: string, active: bool}>}>
     */
    private function newYearAllocations(?RolloverRun $run): array
    {
        if ($run === null || $run->academic_year_to_id === null) {
            return [];
        }

        /** @var list<stdClass> $allocations */
        $allocations = DB::table('subject_allocations as sa')
            ->join('subjects as sub', 'sub.id', '=', 'sa.subject_id')
            ->join('class_levels as cl', 'cl.id', '=', 'sa.class_level_id')
            ->where('sa.academic_year_id', $run->academic_year_to_id)
            ->where('sa.is_active', true)
            ->orderBy('cl.name')
            ->orderBy('sub.code')
            ->get([
                'sa.id', 'sa.class_level_id', 'sa.stream_id', 'sa.subject_id',
                'sub.code as subject_code', 'sub.name as subject_name', 'cl.name as level_name',
            ])
            ->all();

        $rows = [];

        foreach ($allocations as $allocation) {
            /** @var list<stdClass> $teachers */
            $teachers = DB::table('subject_allocations as old')
                ->join('subject_allocation_teachers as sat', 'sat.subject_allocation_id', '=', 'old.id')
                ->join('users as u', 'u.id', '=', 'sat.user_id')
                ->where('old.academic_year_id', $run->academic_year_from_id)
                ->where('old.class_level_id', (int) $allocation->class_level_id)
                ->where('old.stream_id', (int) $allocation->stream_id)
                ->where('old.subject_id', (int) $allocation->subject_id)
                ->orderBy('u.name')
                ->get(['u.name', 'u.status'])
                ->all();

            $rows[] = [
                'id' => (int) $allocation->id,
                'label' => sprintf('%s — %s (%s)', (string) $allocation->level_name, (string) $allocation->subject_name, (string) $allocation->subject_code),
                // Same departed-staff definition as ReassignTeachersStep:
                // anything but user status 'active' is flagged, not carried.
                'inherited' => array_map(static fn (stdClass $teacher): array => [
                    'name' => (string) $teacher->name,
                    'active' => (string) $teacher->status === 'active',
                ], $teachers),
            ];
        }

        return $rows;
    }
}
