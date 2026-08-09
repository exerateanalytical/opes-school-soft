<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Academics\Actions\SetAcademicYearStatus;
use App\Modules\Academics\Actions\SetCurrentAcademicYear;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverArtifact;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reverses a rollover run (docs/specs/08-operations.md §6.3 "Reversible
 * within a window").
 *
 * THE WINDOW IS LIVE DATA, not a status: undo refuses the moment the new
 * year records its first payment, first mark, or first journal entry, and
 * the refusal names which of the three closed the window and when. Rows the
 * rollover itself created (its artifacts, its balance-carry postings) do not
 * close the window - they are exactly what undo removes.
 *
 * The undo ledger (`rollover_artifacts`, phase-07 plan decision 2) is walked
 * in reverse creation order - later steps first, children before parents -
 * deleting through DB::table(), which needs no other module's models. A
 * foreign key that still refuses (a draft invoice referencing a copied fee
 * structure, for example) aborts the whole undo atomically, naming the row.
 *
 * A completed run's year flip is restored from the state FlipActiveYearStep
 * recorded: the previously-current year takes is_current back through the
 * Academics door, and both years' statuses return to their pre-flip values.
 */
final class UndoRollover
{
    public function __construct(
        private readonly SetCurrentAcademicYear $setCurrent,
        private readonly SetAcademicYearStatus $setStatus,
        private readonly WriteAuditEntry $audit,
    ) {
    }

    public function handle(RolloverRun $run, Actor $actor): RolloverRun
    {
        Gate::authorize(StartRolloverRun::PERMISSION);

        if (! $run->status()->isUndoable()) {
            throw new DomainException(sprintf('Rollover run %d has already been undone.', (int) $run->getKey()));
        }

        $toYearId = $run->academic_year_to_id;

        if ($toYearId !== null) {
            $this->assertWindowOpen($run, $toYearId);
        }

        try {
            DB::transaction(function () use ($run, $toYearId, $actor): void {
                $this->restoreFlip($run, $actor);

                // Step-7 outcome rows reference the carry postings; they must
                // go before the artifact walk deletes those journal entries.
                DB::table('rollover_balance_carries')
                    ->where('rollover_run_id', (int) $run->getKey())
                    ->delete();

                // Free the FK on the new year so the artifact walk can
                // delete the year row itself (it was created at step 1).
                $run->forceFill(['academic_year_to_id' => null])->save();

                $artifacts = RolloverArtifact::query()
                    ->where('rollover_run_id', (int) $run->getKey())
                    ->orderByDesc('step')
                    ->orderByDesc('id')
                    ->get();

                foreach ($artifacts as $artifact) {
                    DB::table($artifact->entity_type)->where('id', $artifact->entity_id)->delete();
                    $artifact->delete();
                }

                $run->forceFill(['status' => RolloverRunStatus::Undone->value])->save();

                $this->audit->handle(
                    action: AuditAction::Updated,
                    module: 'Operations',
                    auditableType: RolloverRun::class,
                    auditableId: (int) $run->getKey(),
                    before: ['academic_year_to_id' => $toYearId],
                    after: [
                        'status' => RolloverRunStatus::Undone->value,
                        'artifacts_removed' => $artifacts->count(),
                    ],
                    actor: $actor,
                );
            });
        } catch (QueryException $e) {
            throw new DomainException(
                'Undo was refused by the database: a row the rollover created is referenced by data '
                .'created since (for example an invoice against a copied fee structure). Nothing was changed. '
                .'Detail: '.$e->getMessage(),
            );
        }

        return $run->refresh();
    }

    /**
     * §6.3: undo is available until the new year records its first payment,
     * first mark, or first journal entry - checked live, naming the closer.
     */
    private function assertWindowOpen(RolloverRun $run, int $toYearId): void
    {
        $payment = DB::table('payments')
            ->where('academic_year_id', $toYearId)
            ->whereNotIn('id', $this->artifactIds($run, 'payments'))
            ->orderBy('id')
            ->first();

        if ($payment !== null) {
            throw new DomainException(sprintf(
                'Undo is no longer available: the new year recorded its first payment (receipt %s) on %s.',
                (string) $payment->receipt_no,
                (string) $payment->created_at,
            ));
        }

        $mark = DB::table('marks')
            ->join('assessment_periods', 'assessment_periods.id', '=', 'marks.assessment_period_id')
            ->where('assessment_periods.academic_year_id', $toYearId)
            ->whereNotIn('marks.id', $this->artifactIds($run, 'marks'))
            ->orderBy('marks.id')
            ->select('marks.id AS id', 'marks.created_at AS created_at')
            ->first();

        if ($mark !== null) {
            throw new DomainException(sprintf(
                'Undo is no longer available: the new year recorded its first mark (id %d) on %s.',
                (int) $mark->id,
                (string) $mark->created_at,
            ));
        }

        // The rollover's own carry postings (step 7) are journal entries in
        // the new year created BY the run - they never close its window.
        $ownEntryIds = array_merge(
            $this->artifactIds($run, 'journal_entries'),
            DB::table('rollover_balance_carries')
                ->where('rollover_run_id', (int) $run->getKey())
                ->whereNotNull('journal_entry_id')
                ->pluck('journal_entry_id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
        );

        $entry = DB::table('journal_entries')
            ->where('academic_year_id', $toYearId)
            ->whereNotIn('id', $ownEntryIds)
            ->orderBy('id')
            ->first();

        if ($entry !== null) {
            throw new DomainException(sprintf(
                'Undo is no longer available: the new year recorded its first journal entry (%s) on %s.',
                (string) $entry->label,
                (string) $entry->created_at,
            ));
        }
    }

    /**
     * @return list<int>
     */
    private function artifactIds(RolloverRun $run, string $table): array
    {
        return array_values(RolloverArtifact::query()
            ->where('rollover_run_id', (int) $run->getKey())
            ->where('entity_type', $table)
            ->pluck('entity_id')
            ->map(static fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Reverse what FlipActiveYearStep recorded, through the Academics doors.
     */
    private function restoreFlip(RolloverRun $run, Actor $actor): void
    {
        $states = $run->step_states ?? [];
        $flip = $states[(string) RolloverStep::FlipActiveYear->value] ?? null;

        if (! is_array($flip)) {
            return;
        }

        $previousCurrentId = $flip['previous_current_year_id'] ?? null;

        if (is_int($previousCurrentId) || (is_string($previousCurrentId) && $previousCurrentId !== '')) {
            $this->setCurrent->handle((int) $previousCurrentId, $actor);
        }

        $fromStatusBefore = $flip['from_status_before'] ?? null;

        if (is_string($fromStatusBefore) && $fromStatusBefore !== '') {
            $this->setStatus->handle($run->academic_year_from_id, $fromStatusBefore, $actor);
        }
    }
}
