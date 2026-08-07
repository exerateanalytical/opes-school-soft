<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Models\AssessmentComponent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Opens a leaf assessment period and MATERIALISES its marks
 * (docs/specs/01-assessment.md 6.2, test obligation T2).
 *
 * The correction this Action exists for: in v1, a subject a teacher never
 * opened produced no `Mark` rows at all, so that subject vanished from both the
 * numerator AND the denominator of every affected student's average - and a
 * missing weak subject RAISES a mean. Worse, the pending-publication gate had
 * nothing to detect, because there was nothing to look at. "No row" is not a
 * state; `pending` is.
 *
 * So opening a period writes one `pending` row per
 * (enrollment x active allocation in effect for the period x component in
 * `required_components`), score NULL. Re-running is a no-op on rows that
 * already exist: it is keyed on the marks UNIQUE and inserted with
 * INSERT IGNORE semantics, which is 6.2's `ON DUPLICATE KEY UPDATE id = id`
 * expressed in the query builder. Re-running is normal, not exceptional - a
 * late enrolment, a transfer in, a newly effective allocation or a component
 * added to `required_components` all call it again.
 *
 * Enrollments and subject allocations belong to other modules, so they are read
 * with the query builder and never through their Models (00-core 6.2 rule 2).
 * The insert is a chunked bulk write for the same reason 6.2 specifies one:
 * a reference year is roughly 158 400 rows.
 */
final class OpenAssessmentPeriod
{
    /** See CreateFramework::PERMISSION. */
    public const PERMISSION = CreateFramework::PERMISSION;

    /**
     * Rows per INSERT. MySQL's default max_allowed_packet is 64 MB and these
     * rows are narrow, so this is well inside it while still keeping the
     * statement count for a full year in the low hundreds.
     */
    private const CHUNK = 500;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<int>|null  $enrollmentIds  restrict to these enrolments (00-core 6.2
     *                                         rule 5's batch form); null means every
     *                                         active enrolment in the period's year
     * @return int the number of Mark rows actually created
     */
    public function handle(
        int $assessmentPeriodId,
        Actor $actor,
        ?array $enrollmentIds = null,
        ?string $entryOpensAt = null,
        ?string $entryClosesAt = null,
    ): int {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($assessmentPeriodId, $actor, $enrollmentIds, $entryOpensAt, $entryClosesAt): int {
            $period = DB::table('assessment_periods')
                ->where('id', $assessmentPeriodId)
                ->lockForUpdate()
                ->first();

            if ($period === null) {
                throw new DomainException(sprintf('Assessment period %d does not exist.', $assessmentPeriodId));
            }

            // 01-assessment 4.1: marks attach only to leaf periods. A mark on a
            // term would be double-counted by the very composition that reads
            // the term's children.
            $hasChildren = DB::table('assessment_periods')
                ->where('parent_id', $assessmentPeriodId)
                ->exists();

            if ($hasChildren) {
                throw new DomainException(sprintf(
                    'Period `%s` has child periods, so marks do not attach to it (01-assessment 4.1). '
                    .'Open its leaves instead.',
                    (string) $period->code,
                ));
            }

            $frameworkId = $period->framework_id === null ? null : (int) $period->framework_id;

            if ($frameworkId === null) {
                throw new DomainException(sprintf(
                    'Period `%s` has no assessment framework, so there are no components to materialise '
                    .'(01-assessment 3.1).',
                    (string) $period->code,
                ));
            }

            if ($entryOpensAt !== null || $entryClosesAt !== null) {
                $this->applyEntryWindow($assessmentPeriodId, $entryOpensAt, $entryClosesAt);
            }

            $created = $this->materialise(
                $assessmentPeriodId,
                (int) $period->academic_year_id,
                $frameworkId,
                (string) $period->starts_on,
                (string) $period->ends_on,
                $enrollmentIds,
            );

            DB::table('assessment_periods')
                ->where('id', $assessmentPeriodId)
                ->update(['status' => 'open', 'updated_at' => now()]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: 'assessment_periods',
                auditableId: $assessmentPeriodId,
                before: ['status' => (string) $period->status],
                after: [
                    'status' => 'open',
                    'marks_materialised' => $created,
                    'scope' => $enrollmentIds === null ? 'all active enrolments' : count($enrollmentIds).' enrolments',
                ],
                actor: $actor,
            );

            return $created;
        });
    }

    /**
     * @param  list<int>|null  $enrollmentIds
     */
    private function materialise(
        int $periodId,
        int $academicYearId,
        int $frameworkId,
        string $periodStartsOn,
        string $periodEndsOn,
        ?array $enrollmentIds,
    ): int {
        // Only components that belong to THIS framework and are active can be
        // materialised: `required_components` is a JSON id list on an
        // allocation and nothing in the database stops it naming a component
        // from another framework, so it is filtered rather than trusted.
        /** @var list<int> $validComponents */
        $validComponents = AssessmentComponent::query()
            ->where('framework_id', $frameworkId)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($validComponents === []) {
            throw new DomainException(
                'The framework declares no active components, so there is nothing to materialise '
                .'(01-assessment 5.3).'
            );
        }

        $componentSet = array_flip($validComponents);

        $enrollments = DB::table('enrollments')
            ->where('academic_year_id', $academicYearId)
            ->where('status', 'active')
            ->when(
                $enrollmentIds !== null,
                static fn ($query) => $query->whereIn('id', $enrollmentIds ?? []),
            )
            ->get(['id', 'class_level_id', 'stream_id']);

        $allocations = $this->allocationsInEffect($academicYearId, $periodStartsOn, $periodEndsOn);

        $columns = array_flip(Schema::getColumnListing('marks'));
        $now = now();
        $rows = [];
        $created = 0;

        foreach ($enrollments as $enrollment) {
            $levelId = (int) $enrollment->class_level_id;
            $streamId = $enrollment->stream_id === null ? 0 : (int) $enrollment->stream_id;

            foreach ($allocations as $allocation) {
                // stream_id 0 is the "whole level" sentinel, so a level-wide
                // allocation applies to a streamed student too.
                if ((int) $allocation->class_level_id !== $levelId) {
                    continue;
                }

                $allocationStream = (int) $allocation->stream_id;

                if ($allocationStream !== 0 && $allocationStream !== $streamId) {
                    continue;
                }

                foreach ($this->requiredComponents($allocation->required_components, $componentSet) as $componentId) {
                    $rows[] = array_intersect_key([
                        'enrollment_id' => (int) $enrollment->id,
                        'subject_allocation_id' => (int) $allocation->id,
                        'assessment_period_id' => $periodId,
                        'component_id' => $componentId,
                        'score' => null,
                        'state' => 'pending',
                        'workflow_state' => 'draft',
                        'attempt_no' => 1,
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $columns);

                    if (count($rows) >= self::CHUNK) {
                        $created += $this->insertIgnore($rows);
                        $rows = [];
                    }
                }
            }
        }

        if ($rows !== []) {
            $created += $this->insertIgnore($rows);
        }

        return $created;
    }

    /**
     * `insertOrIgnore` compiles to MySQL's INSERT IGNORE, which is 6.2's
     * `ON DUPLICATE KEY UPDATE id = id` with the same effect and without the
     * self-assignment: a row that already exists is left exactly as it was,
     * so re-running never resets a teacher's entered score back to pending.
     * The return value is the count of rows genuinely inserted, which is what
     * makes "re-run creates no duplicates" assertable rather than inferred.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertIgnore(array $rows): int
    {
        return DB::table('marks')->insertOrIgnore($rows);
    }

    /**
     * Allocations whose effective range covers this period.
     *
     * `effective_from_period_id` / `effective_to_period_id` name periods, not
     * dates, so they are resolved to their own date ranges and compared with
     * the target period's. Comparing ids directly would only work while the
     * bounds happen to be siblings at the same depth; comparing dates works
     * for a bound set on a term and a target that is one of its sequences.
     *
     * @return list<object{id: int, class_level_id: int, stream_id: int, required_components: mixed}>
     */
    private function allocationsInEffect(int $academicYearId, string $periodStartsOn, string $periodEndsOn): array
    {
        $rows = DB::table('subject_allocations as sa')
            ->leftJoin('assessment_periods as pf', 'pf.id', '=', 'sa.effective_from_period_id')
            ->leftJoin('assessment_periods as pt', 'pt.id', '=', 'sa.effective_to_period_id')
            ->where('sa.academic_year_id', $academicYearId)
            ->where('sa.is_active', true)
            // The lower bound is inclusive: an allocation effective from a
            // period starts counting in that period, not after it.
            ->where(function ($query) use ($periodStartsOn): void {
                $query->whereNull('sa.effective_from_period_id')
                    ->orWhere('pf.starts_on', '<=', $periodStartsOn);
            })
            // 01-assessment 5.1 states the upper bound is INCLUSIVE.
            ->where(function ($query) use ($periodEndsOn): void {
                $query->whereNull('sa.effective_to_period_id')
                    ->orWhere('pt.ends_on', '>=', $periodEndsOn);
            })
            ->get(['sa.id', 'sa.class_level_id', 'sa.stream_id', 'sa.required_components']);

        /** @var list<object{id: int, class_level_id: int, stream_id: int, required_components: mixed}> $out */
        $out = $rows->all();

        return $out;
    }

    /**
     * @param  array<int, mixed>  $componentSet  component_id => position, used as a set
     * @return list<int>
     */
    private function requiredComponents(mixed $raw, array $componentSet): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($decoded)) {
            return [];
        }

        $ids = [];

        foreach ($decoded as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                continue;
            }

            $id = (int) $value;

            // Silently skipping an id from another framework would hide a
            // configuration error; skipping one that is merely inactive is
            // correct. Both are filtered the same way here because the caller
            // has no way to tell them apart from a JSON column, and the
            // publication gate reports the resulting empty grid loudly.
            if (isset($componentSet[$id])) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function applyEntryWindow(int $periodId, ?string $opensAt, ?string $closesAt): void
    {
        $opens = $opensAt === null ? null : Carbon::parse($opensAt, BusinessDate::TIMEZONE);
        $closes = $closesAt === null ? null : Carbon::parse($closesAt, BusinessDate::TIMEZONE);

        if ($opens !== null && $closes !== null && $closes->lessThan($opens)) {
            throw new DomainException(sprintf(
                'The marks-entry window closes (%s) before it opens (%s).',
                $closes->format('Y-m-d H:i'),
                $opens->format('Y-m-d H:i'),
            ));
        }

        DB::table('assessment_periods')->where('id', $periodId)->update([
            'marks_entry_opens_at' => $opens?->format('Y-m-d H:i:s'),
            'marks_entry_closes_at' => $closes?->format('Y-m-d H:i:s'),
            'updated_at' => now(),
        ]);
    }

    /**
     * The entry-window test of 01-assessment 7.6, and test obligation T18.
     *
     * `marks_entry_opens_at` / `closes_at` are stored as LOCAL wall-clock
     * datetimes and must be compared against local wall-clock now. Cameroon is
     * UTC+1 with no DST, so between 00:00 and 01:00 local the UTC clock still
     * reads the previous day - and a window opening at 00:00 on closing day
     * would reject a legitimate 00:30 save, which is exactly when a teacher
     * finishing a class set is working.
     *
     * Evaluated ONCE, at transaction start: a window that expires mid-request
     * must not fail half of a batch save.
     *
     * A NULL bound means unbounded on that side.
     */
    public static function entryWindowIsOpen(?string $opensAt, ?string $closesAt, ?Carbon $at = null): bool
    {
        $now = $at?->copy()->setTimezone(BusinessDate::TIMEZONE)
            ?? Carbon::now(BusinessDate::TIMEZONE);

        if ($opensAt !== null && $now->lessThan(Carbon::parse($opensAt, BusinessDate::TIMEZONE))) {
            return false;
        }

        if ($closesAt !== null && $now->greaterThan(Carbon::parse($closesAt, BusinessDate::TIMEZONE))) {
            return false;
        }

        return true;
    }

    /**
     * The same test, refusing with the window printed in the message as 7.6
     * requires - an operator told only "outside the entry window" cannot tell
     * whether they are early, late, or looking at the wrong period.
     */
    public static function assertEntryWindowOpen(?string $opensAt, ?string $closesAt, ?Carbon $at = null): void
    {
        if (self::entryWindowIsOpen($opensAt, $closesAt, $at)) {
            return;
        }

        throw new DomainException(sprintf(
            'Marks entry is closed. The window runs %s to %s (%s).',
            $opensAt ?? 'any time',
            $closesAt ?? 'any time',
            BusinessDate::TIMEZONE,
        ));
    }
}
