<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Domain\AssessmentPeriodStatus;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Copies one academic year's assessment-period tree onto another year, dates
 * shifted by the whole-year offset, statuses reset to `planned`, marks-entry
 * windows cleared for re-scheduling. This is the Academics-owned door the
 * rollover wizard's step 4 walks through (docs/specs/08-operations.md §6.2;
 * cross-module writes go through the owning module's Actions - phase-07 plan
 * decision 1).
 *
 * The Σweights invariant is RE-VALIDATED before any write (§6.2 step 4
 * guard): every node's weight must be positive, and every parent whose
 * children count toward it must keep a positive participating sum
 * (01-assessment §9.1 normalises by that sum - zero would divide by zero).
 *
 * Idempotent by the per-year (code) natural key: nodes already present on
 * the target year are adopted as parents but not re-created, and only newly
 * created ids are returned - exactly what the rollover's undo ledger needs.
 */
final class CopyAssessmentPeriodTree
{
    /** See CreateAcademicYear::PERMISSION for why this is a raw string. */
    public const PERMISSION = CreateAcademicYear::PERMISSION;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @return list<int> ids of the periods CREATED on the target year, parents first
     */
    public function handle(int $fromAcademicYearId, int $toAcademicYearId, Actor $actor): array
    {
        Gate::authorize(self::PERMISSION);

        if ($fromAcademicYearId === $toAcademicYearId) {
            throw new DomainException('Cannot copy an assessment-period tree onto its own year.');
        }

        /** @var AcademicYear $from */
        $from = AcademicYear::query()->findOrFail($fromAcademicYearId);
        /** @var AcademicYear $to */
        $to = AcademicYear::query()->findOrFail($toAcademicYearId);

        $offsetDays = (int) $from->starts_on->diffInDays($to->starts_on);

        $source = AssessmentPeriod::query()
            ->where('academic_year_id', $fromAcademicYearId)
            ->orderBy('id')
            ->get();

        if ($source->isEmpty()) {
            return [];
        }

        $this->assertWeights($source->all());

        return DB::transaction(function () use ($source, $toAcademicYearId, $offsetDays, $to, $from, $actor): array {
            /** @var array<int, int> $idMap source period id => target period id */
            $idMap = [];
            $createdIds = [];

            // Parents were created before their children (ids ascend), so a
            // single ordered pass always finds the mapped parent.
            foreach ($source as $period) {
                $existing = AssessmentPeriod::query()
                    ->where('academic_year_id', $toAcademicYearId)
                    ->where('code', $period->code)
                    ->first();

                if ($existing !== null) {
                    $idMap[(int) $period->getKey()] = (int) $existing->getKey();

                    continue;
                }

                $parentId = null;

                if ($period->parent_id !== null) {
                    $parentId = $idMap[(int) $period->parent_id]
                        ?? throw new DomainException(sprintf(
                            'Period %s references parent %d which was not copied - the source tree is inconsistent.',
                            $period->code,
                            (int) $period->parent_id,
                        ));
                }

                $copy = AssessmentPeriod::query()->create([
                    'academic_year_id' => $toAcademicYearId,
                    'framework_id' => $period->framework_id,
                    'parent_id' => $parentId,
                    'type' => $period->type,
                    'code' => $period->code,
                    'name' => $period->name,
                    'name_fr' => $period->name_fr,
                    'order_index' => $period->order_index,
                    'starts_on' => $period->starts_on->copy()->addDays($offsetDays)->toDateString(),
                    'ends_on' => $period->ends_on->copy()->addDays($offsetDays)->toDateString(),
                    'weight' => $period->weight,
                    'counts_toward_parent' => $period->counts_toward_parent,
                    // Presented for date correction in the wizard (§6.2 step 4)
                    // - a copied entry window would silently open marks entry
                    // on last year's calendar.
                    'marks_entry_opens_at' => null,
                    'marks_entry_closes_at' => null,
                    'is_reporting_period' => $period->is_reporting_period,
                    'status' => AssessmentPeriodStatus::Planned,
                ]);

                $copyId = (int) $copy->getKey();
                $idMap[(int) $period->getKey()] = $copyId;
                $createdIds[] = $copyId;
            }

            if ($createdIds !== []) {
                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Academics',
                    auditableType: AssessmentPeriod::class,
                    auditableId: $createdIds[0],
                    after: [
                        'copied_from_year' => $from->code,
                        'copied_to_year' => $to->code,
                        'periods_created' => count($createdIds),
                        'day_offset' => $offsetDays,
                    ],
                    actor: $actor,
                );
            }

            return $createdIds;
        });
    }

    /**
     * @param  array<int, AssessmentPeriod>  $periods
     */
    private function assertWeights(array $periods): void
    {
        /** @var array<int, numeric-string> $participatingSums parent id => Σweight */
        $participatingSums = [];
        $hasChildren = [];

        foreach ($periods as $period) {
            $weight = $period->weight;

            if (! is_numeric($weight) || bccomp($weight, '0', 4) <= 0) {
                throw new DomainException(sprintf(
                    'Period %s has a non-positive weight (%s); the Σweights invariant fails and the tree cannot be copied.',
                    $period->code,
                    $period->weight,
                ));
            }

            if ($period->parent_id === null) {
                continue;
            }

            $parentId = (int) $period->parent_id;
            $hasChildren[$parentId] = true;

            if ($period->counts_toward_parent) {
                $participatingSums[$parentId] = bcadd($participatingSums[$parentId] ?? '0', $weight, 4);
            }
        }

        $byId = [];

        foreach ($periods as $period) {
            $byId[(int) $period->getKey()] = $period;
        }

        foreach (array_keys($hasChildren) as $parentId) {
            $sum = $participatingSums[$parentId] ?? '0';

            if (bccomp($sum, '0', 4) <= 0) {
                $code = isset($byId[$parentId]) ? $byId[$parentId]->code : (string) $parentId;

                throw new DomainException(sprintf(
                    'Period %s has children but none counts toward it (participating Σweight = 0); composition would divide by zero. Fix the source tree first.',
                    $code,
                ));
            }
        }
    }
}
