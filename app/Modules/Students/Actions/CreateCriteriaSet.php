<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\Comparator;
use App\Modules\Students\Domain\CriterionType;
use App\Modules\Students\Models\PromotionCriteriaSet;
use App\Modules\Students\Models\PromotionCriterion;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md §10.4 — create the promotion rulebook a run is
 * evaluated against.
 *
 * Two rules with teeth:
 *
 *  - `fee_clearance` is ADVISORY BY DEFAULT. Whether a school may withhold
 *    promotion for unpaid fees is a policy and possibly legal question, so
 *    `is_blocking` on that criterion is refused unless the caller passes the
 *    explicit written-warning acknowledgement — the UI shows the warning text
 *    and the operator ticks it; an API caller must send it too.
 *  - Once ANY run references a set, the set is immutable (00-core versioning
 *    pattern). This Action only creates; there is deliberately no update
 *    door — a school that wants different rules creates version n+1.
 */
final class CreateCriteriaSet
{
    public const PERMISSION = Permission::PromotionEvaluate->value;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  list<array{
     *     type: string,
     *     comparator: string,
     *     threshold: string|int|float,
     *     subject_id?: int|null,
     *     is_blocking?: bool,
     *     weight?: string|int|float,
     * }>  $criteria
     */
    public function handle(
        int $academicYearId,
        int $schoolSectionId,
        ?int $classLevelId,
        string $name,
        array $criteria,
        bool $acceptFeeClearanceBlockWarning = false,
        ?Actor $actor = null,
    ): PromotionCriteriaSet {
        Gate::authorize(self::PERMISSION);

        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'A criteria set needs a name the promotion list can print.',
            ]);
        }

        if ($criteria === []) {
            throw ValidationException::withMessages([
                'criteria' => 'A criteria set with no criteria cannot judge anyone.',
            ]);
        }

        $normalised = $this->normalise($criteria, $acceptFeeClearanceBlockWarning);

        $writer = $actor ?? $this->currentActor();

        return DB::transaction(function () use (
            $academicYearId, $schoolSectionId, $classLevelId, $name, $normalised, $writer
        ): PromotionCriteriaSet {
            $this->assertScopeExists($academicYearId, $schoolSectionId, $classLevelId);

            $set = PromotionCriteriaSet::query()->create([
                'academic_year_id' => $academicYearId,
                'school_section_id' => $schoolSectionId,
                'class_level_id' => $classLevelId,
                'name' => $name,
                'is_active' => true,
                'version' => 1,
                'created_by' => $writer->id,
            ]);

            foreach ($normalised as $sequence => $criterion) {
                PromotionCriterion::query()->create([
                    'criteria_set_id' => (int) $set->getKey(),
                    'type' => $criterion['type'],
                    'comparator' => $criterion['comparator'],
                    'threshold' => $criterion['threshold'],
                    'subject_id' => $criterion['subject_id'],
                    'weight' => $criterion['weight'],
                    'is_blocking' => $criterion['is_blocking'],
                    'sequence' => $sequence,
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Students',
                auditableType: PromotionCriteriaSet::class,
                auditableId: (int) $set->getKey(),
                after: [
                    'academic_year_id' => $academicYearId,
                    'school_section_id' => $schoolSectionId,
                    'class_level_id' => $classLevelId,
                    'name' => $name,
                    'criteria' => array_map(
                        static fn (array $criterion): array => [
                            'type' => $criterion['type']->value,
                            'comparator' => $criterion['comparator']->value,
                            'threshold' => $criterion['threshold'],
                            'is_blocking' => $criterion['is_blocking'],
                        ],
                        $normalised,
                    ),
                ],
                actor: $writer,
            );

            return $set;
        });
    }

    /**
     * @param  list<array{
     *     type: string,
     *     comparator: string,
     *     threshold: string|int|float,
     *     subject_id?: int|null,
     *     is_blocking?: bool,
     *     weight?: string|int|float,
     * }>  $criteria
     * @return list<array{
     *     type: CriterionType,
     *     comparator: Comparator,
     *     threshold: string,
     *     subject_id: int|null,
     *     weight: string,
     *     is_blocking: bool,
     * }>
     */
    private function normalise(array $criteria, bool $acceptFeeClearanceBlockWarning): array
    {
        $normalised = [];
        $seenTypes = [];

        foreach ($criteria as $index => $row) {
            $type = CriterionType::tryFrom($row['type']);

            if ($type === null) {
                throw ValidationException::withMessages([
                    "criteria.{$index}.type" => "'{$row['type']}' is not a promotion criterion type.",
                ]);
            }

            $comparator = Comparator::tryFrom($row['comparator']);

            if ($comparator === null) {
                throw ValidationException::withMessages([
                    "criteria.{$index}.comparator" => "'{$row['comparator']}' is not a comparator.",
                ]);
            }

            if (! is_numeric($row['threshold'])) {
                throw ValidationException::withMessages([
                    "criteria.{$index}.threshold" => 'The threshold must be a number.',
                ]);
            }

            $subjectId = $row['subject_id'] ?? null;

            if ($type->requiresSubject() && $subjectId === null) {
                throw ValidationException::withMessages([
                    "criteria.{$index}.subject_id" => 'A subject_minimum criterion must name its subject.',
                ]);
            }

            if (! $type->requiresSubject() && $subjectId !== null) {
                throw ValidationException::withMessages([
                    "criteria.{$index}.subject_id" => "A {$type->value} criterion does not take a subject.",
                ]);
            }

            // §10.4's default matrix: fee_clearance advisory unless the
            // written warning is acknowledged; everything else blocking
            // unless the school says otherwise.
            $isBlocking = (bool) ($row['is_blocking'] ?? ($type !== CriterionType::FeeClearance));

            if ($type === CriterionType::FeeClearance && $isBlocking && ! $acceptFeeClearanceBlockWarning) {
                throw ValidationException::withMessages([
                    "criteria.{$index}.is_blocking" => 'Withholding promotion for unpaid fees is a policy '
                        .'decision with possible legal consequences; enabling a blocking fee_clearance '
                        .'criterion requires the explicit written-warning acknowledgement.',
                ]);
            }

            // Duplicate non-subject criteria are configuration mistakes; two
            // subject_minimum rows for two subjects are not.
            $typeKey = $type->value.':'.($subjectId ?? 0);

            if (isset($seenTypes[$typeKey])) {
                throw ValidationException::withMessages([
                    "criteria.{$index}.type" => "The set already contains a {$type->value} criterion for this target.",
                ]);
            }

            $seenTypes[$typeKey] = true;

            $normalised[] = [
                'type' => $type,
                'comparator' => $comparator,
                'threshold' => number_format((float) $row['threshold'], 3, '.', ''),
                'subject_id' => $subjectId === null ? null : (int) $subjectId,
                'weight' => number_format((float) ($row['weight'] ?? 0), 2, '.', ''),
                'is_blocking' => $isBlocking,
            ];
        }

        return $normalised;
    }

    /**
     * Cross-module existence checks through the query builder — AcademicYear,
     * SchoolSection and ClassLevel are Academics Models this module must not
     * import (tests/Architecture/ModuleBoundaryTest.php).
     */
    private function assertScopeExists(int $academicYearId, int $schoolSectionId, ?int $classLevelId): void
    {
        if (! DB::table('academic_years')->where('id', $academicYearId)->exists()) {
            throw ValidationException::withMessages([
                'academic_year_id' => 'The academic year does not exist.',
            ]);
        }

        if (! DB::table('school_sections')->where('id', $schoolSectionId)->exists()) {
            throw ValidationException::withMessages([
                'school_section_id' => 'The school section does not exist.',
            ]);
        }

        if ($classLevelId !== null) {
            $sectionOfLevel = DB::table('class_levels')->where('id', $classLevelId)->value('school_section_id');

            if (! is_numeric($sectionOfLevel)) {
                throw ValidationException::withMessages([
                    'class_level_id' => 'The class level does not exist.',
                ]);
            }

            if ((int) $sectionOfLevel !== $schoolSectionId) {
                throw ValidationException::withMessages([
                    'class_level_id' => 'The class level belongs to a different school section.',
                ]);
            }
        }
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
