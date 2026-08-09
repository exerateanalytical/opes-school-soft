<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Operations\Actions\AssertEntitlement;
use App\Modules\Operations\Actions\Rollover\Support\RolloverStepMechanics;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use stdClass;

/**
 * Step 0 of the year-rollover wizard (docs/specs/08-operations.md §6.2): the
 * mandatory pre-flight. Refuses to open a run until
 *
 *  1. a VERIFIED backup exists (§6.2 step 0, "no override"),
 *  2. the outgoing year has no unpublished reporting period, and
 *  3. the outgoing year has no draft journal entries.
 *
 * The spec also lists "no open cash desk"; Fees has no cash-desk session
 * table yet, so that guard is DEFERRED (phase-07 plan §5, risk 3) - the
 * draft-journal-entry check is the closest available proxy. Whoever builds
 * cash-desk sessions must extend this pre-flight.
 *
 * Idempotent per §6.3: one run per outgoing year at a time. Calling again
 * while a run is resumable RESUMES it (after re-validating the pre-flight
 * and the inputs hash); calling after a completed run refuses.
 *
 * ENTITLEMENT: phase-07 plan decision 6 places an
 * `Operations\Actions\AssertEntitlement` call at the top of this handle() -
 * the rollover wizard is one of the four annual/termly operations blocked
 * when expired-enforced (08-operations §4.4).
 */
final class StartRolloverRun
{
    /**
     * Raw string until Identity's Permission enum gains
     * `RolloverRun = 'rollover.run'` (single-owner file, wired by F5).
     * Chosen to match the module.action convention exactly.
     */
    public const PERMISSION = 'rollover.run';

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $fromAcademicYearId, int $backupId, Actor $actor): RolloverRun
    {
        Gate::authorize(self::PERMISSION);

        // Entitlement gate (08-operations §4.4): the rollover wizard is one
        // of the four annual operations blocked when expired-enforced.
        app(AssertEntitlement::class)->handle('operations.rollover');

        $from = RolloverStepMechanics::yearRow($fromAcademicYearId);

        $existing = RolloverRun::query()
            ->where('academic_year_from_id', $fromAcademicYearId)
            ->whereIn('status', [
                RolloverRunStatus::Running->value,
                RolloverRunStatus::Failed->value,
                RolloverRunStatus::Completed->value,
            ])
            ->orderByDesc('id')
            ->first();

        if ($existing !== null && $existing->status() === RolloverRunStatus::Completed) {
            throw new DomainException(sprintf(
                'Academic year %s has already been rolled over (run %d). Undo that run before starting another.',
                (string) $from->code,
                (int) $existing->getKey(),
            ));
        }

        if ($existing !== null) {
            return $this->resume($existing, $from, $actor);
        }

        $this->assertVerifiedBackup($backupId);
        $this->assertNoUnpublishedPeriods($from);
        $this->assertNoDraftJournalEntries($from);

        if ($actor->id === null) {
            throw new DomainException('A rollover run needs an authenticated operator.');
        }

        $operatorId = $actor->id;

        return DB::transaction(function () use ($fromAcademicYearId, $from, $backupId, $operatorId, $actor): RolloverRun {
            $run = RolloverRun::query()->create([
                'academic_year_from_id' => $fromAcademicYearId,
                'academic_year_to_id' => null,
                'current_step' => RolloverStep::CreateNewYear->value,
                'step_states' => [
                    (string) RolloverStep::Preflight->value => [
                        'completed_at' => now()->toIso8601String(),
                        'backup_id' => $backupId,
                        'unpublished_reporting_periods' => 0,
                        'draft_journal_entries' => 0,
                        'cash_desk_check' => 'deferred: no cash-desk session table yet',
                    ],
                ],
                'inputs_hash' => self::inputsHash($from),
                'status' => RolloverRunStatus::Running->value,
                'operator_id' => $operatorId,
                'backup_id' => $backupId,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Operations',
                auditableType: RolloverRun::class,
                auditableId: (int) $run->getKey(),
                after: [
                    'academic_year_from' => (string) $from->code,
                    'backup_id' => $backupId,
                    'step' => RolloverStep::Preflight->value,
                ],
                actor: $actor,
            );

            return $run;
        });
    }

    /**
     * §6.3 "Restarting resumes at the first incomplete step and re-validates
     * the earlier ones": the pre-flight record checks are re-asserted, and the
     * inputs hash proves nobody edited the outgoing year mid-run.
     */
    private function resume(RolloverRun $run, stdClass $from, Actor $actor): RolloverRun
    {
        if ($run->inputs_hash !== null && $run->inputs_hash !== self::inputsHash($from)) {
            throw new DomainException(sprintf(
                'Rollover run %d cannot resume: the outgoing year\'s dates changed since the run started. Undo the run and start again.',
                (int) $run->getKey(),
            ));
        }

        $this->assertNoUnpublishedPeriods($from);
        $this->assertNoDraftJournalEntries($from);

        if ($run->status() === RolloverRunStatus::Failed) {
            $run->forceFill(['status' => RolloverRunStatus::Running->value])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Operations',
                auditableType: RolloverRun::class,
                auditableId: (int) $run->getKey(),
                before: ['status' => RolloverRunStatus::Failed->value],
                after: ['status' => RolloverRunStatus::Running->value, 'resumed_at_step' => $run->current_step],
                actor: $actor,
            );
        }

        return $run;
    }

    /**
     * Wizard-input fingerprint (§6.3): the outgoing year's identity and dates.
     * A resumed run recomputes and refuses on mismatch, so an interrupted run
     * finishes byte-identical to an uninterrupted one.
     */
    public static function inputsHash(stdClass $fromYear): string
    {
        return hash('sha256', json_encode([
            'from_id' => (int) $fromYear->id,
            'starts_on' => (string) $fromYear->starts_on,
            'ends_on' => (string) $fromYear->ends_on,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertVerifiedBackup(int $backupId): void
    {
        $backup = Backup::query()->find($backupId);

        if ($backup === null) {
            throw new DomainException(sprintf('Backup %d does not exist - the rollover needs a verified backup first.', $backupId));
        }

        if ($backup->status !== 'healthy' || $backup->verified_at === null) {
            throw new DomainException(sprintf(
                'Backup %d is not verified healthy (status: %s). The rollover refuses to start without a verified backup - there is no override.',
                $backupId,
                $backup->status,
            ));
        }
    }

    private function assertNoUnpublishedPeriods(stdClass $fromYear): void
    {
        $codes = DB::table('assessment_periods')
            ->where('academic_year_id', (int) $fromYear->id)
            ->where('is_reporting_period', true)
            ->where('status', '!=', 'closed')
            ->orderBy('order_index')
            ->pluck('code');

        if ($codes->isNotEmpty()) {
            throw new DomainException(sprintf(
                'Academic year %s still has unpublished reporting periods: %s. Publish and close them before rolling over.',
                (string) $fromYear->code,
                $codes->implode(', '),
            ));
        }
    }

    private function assertNoDraftJournalEntries(stdClass $fromYear): void
    {
        $drafts = (int) DB::table('journal_entries')
            ->where('academic_year_id', (int) $fromYear->id)
            ->where('status', 'draft')
            ->count();

        if ($drafts > 0) {
            throw new DomainException(sprintf(
                'Academic year %s has %d draft journal entr%s. Post or delete them before rolling over.',
                (string) $fromYear->code,
                $drafts,
                $drafts === 1 ? 'y' : 'ies',
            ));
        }
    }
}
