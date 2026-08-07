<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Domain\AssessmentPeriodStatus;
use App\Modules\Academics\Domain\AssessmentPeriodType;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates the assessment-period skeleton for a year: one year-root period
 * plus 2 or 3 term children (01-assessment 4.2's standard shapes, minus the
 * leaf sequences, which the Assessment phase owns).
 */
final class DefineTermStructure
{
    /** See CreateAcademicYear::PERMISSION for why this is a raw string. */
    public const PERMISSION = CreateAcademicYear::PERMISSION;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array{starts_on: string, ends_on: string}>  $termDates
     * @return AssessmentPeriod the year-root period, with `children` loaded
     */
    public function handle(int $academicYearId, int $termCount, array $termDates, Actor $actor): AssessmentPeriod
    {
        Gate::authorize(self::PERMISSION);

        if ($termCount !== 2 && $termCount !== 3) {
            throw new DomainException(sprintf('A term structure has 2 or 3 terms, got %d.', $termCount));
        }

        if (count($termDates) !== $termCount) {
            throw new DomainException(sprintf(
                'Expected %d term date ranges, got %d.',
                $termCount,
                count($termDates),
            ));
        }

        return DB::transaction(function () use ($academicYearId, $termCount, $termDates, $actor): AssessmentPeriod {
            /** @var AcademicYear $year */
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($academicYearId);

            // Duplicate guard: the year lock above serialises two concurrent
            // definitions, so the second one reliably sees the first's rows.
            if (AssessmentPeriod::query()->where('academic_year_id', $year->id)->exists()) {
                throw new DomainException(sprintf(
                    'A term structure already exists for %s. Amend it, do not redefine it.',
                    $year->code,
                ));
            }

            // Marks guard: once the Assessment phase lands, redefinition must
            // also be refused when any Mark row exists under this year's
            // periods. No Mark entity exists in Phase 1, so the duplicate
            // guard above is currently the stronger of the two checks - but
            // whoever wires Mark up must extend this Action, which is why the
            // requirement is recorded here.

            $terms = $this->validatedTerms($year, $termDates);

            $root = AssessmentPeriod::query()->create([
                'academic_year_id' => $year->id,
                'framework_id' => null,
                'parent_id' => null,
                'type' => AssessmentPeriodType::Year,
                'code' => 'YEAR',
                'name' => $year->name,
                'name_fr' => $year->name,
                'order_index' => 0,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
                'weight' => '1.0000',
                'counts_toward_parent' => true,
                'is_reporting_period' => false,
                'status' => AssessmentPeriodStatus::Planned,
            ]);

            foreach ($terms as $i => [$starts, $ends]) {
                $index = $i + 1;

                AssessmentPeriod::query()->create([
                    'academic_year_id' => $year->id,
                    'framework_id' => null,
                    'parent_id' => $root->id,
                    'type' => AssessmentPeriodType::Term,
                    'code' => 'T'.$index,
                    'name' => 'Term '.$index,
                    'name_fr' => ($termCount === 3 ? 'Trimestre ' : 'Semestre ').$index,
                    'order_index' => $index,
                    'starts_on' => $starts->toDateString(),
                    'ends_on' => $ends->toDateString(),
                    'weight' => '1.0000',
                    'counts_toward_parent' => true,
                    'is_reporting_period' => true,
                    'status' => AssessmentPeriodStatus::Planned,
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: AssessmentPeriod::class,
                auditableId: (int) $root->getKey(),
                after: [
                    'academic_year' => $year->code,
                    'term_count' => $termCount,
                    'terms' => array_map(
                        static fn (array $t): array => [
                            'starts_on' => $t[0]->toDateString(),
                            'ends_on' => $t[1]->toDateString(),
                        ],
                        $terms,
                    ),
                ],
                actor: $actor,
            );

            return $root->load('children');
        });
    }

    /**
     * Terms must each be a valid range, sit within the year, and be
     * non-overlapping-contiguous: each term starts exactly the day after the
     * previous one ends. Gaps and overlaps are both rejected - a date inside
     * the taught year that resolves to zero or two terms breaks reporting the
     * same way a gap between YEARS breaks the ledger (00-core 8).
     *
     * @param  list<array{starts_on: string, ends_on: string}>  $termDates
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function validatedTerms(AcademicYear $year, array $termDates): array
    {
        $terms = [];

        foreach ($termDates as $i => $range) {
            $starts = Carbon::parse($range['starts_on'])->startOfDay();
            $ends = Carbon::parse($range['ends_on'])->startOfDay();
            $label = 'Term '.($i + 1);

            if ($ends->lessThan($starts)) {
                throw new DomainException(sprintf(
                    '%s must end on or after its start: got %s to %s.',
                    $label,
                    $starts->toDateString(),
                    $ends->toDateString(),
                ));
            }

            if ($starts->lessThan($year->starts_on) || $ends->greaterThan($year->ends_on)) {
                throw new DomainException(sprintf(
                    '%s (%s to %s) falls outside academic year %s (%s to %s).',
                    $label,
                    $starts->toDateString(),
                    $ends->toDateString(),
                    $year->code,
                    $year->starts_on->toDateString(),
                    $year->ends_on->toDateString(),
                ));
            }

            if ($terms !== []) {
                $previousEnds = $terms[count($terms) - 1][1];
                $expected = $previousEnds->copy()->addDay();

                if (! $starts->isSameDay($expected)) {
                    throw new DomainException(sprintf(
                        'Terms must be contiguous and non-overlapping: %s must start on %s (the day after the previous term ends on %s), got %s.',
                        $label,
                        $expected->toDateString(),
                        $previousEnds->toDateString(),
                        $starts->toDateString(),
                    ));
                }
            }

            $terms[] = [$starts, $ends];
        }

        return $terms;
    }
}
