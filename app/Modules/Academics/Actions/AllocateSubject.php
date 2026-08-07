<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\SubjectAllocation;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AllocateSubject
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * Put a subject on a class list for one academic year.
     *
     * A null $streamId means "the whole level" and is stored as the 0
     * sentinel (SubjectAllocation::STREAM_NONE), never as NULL - MySQL's
     * UNIQUE index treats every NULL as distinct, which would allow the
     * same subject to be allocated twice to a stream-less level.
     *
     * @param  list<int>  $requiredComponents
     */
    public function handle(
        int $academicYearId,
        int $classLevelId,
        ?int $streamId,
        int $subjectId,
        string $coefficient,
        array $requiredComponents = [],
        ?int $subjectGroupId = null,
        ?string $maxScoreOverride = null,
        bool $isOptional = false,
        bool $countsTowardAverage = true,
        ?int $effectiveFromPeriodId = null,
        ?int $effectiveToPeriodId = null,
    ): SubjectAllocation {
        Gate::authorize(Permission::AcademicsManage->value);

        if ((float) $coefficient < 0.0) {
            throw new DomainException('A subject coefficient cannot be negative.');
        }

        try {
            return DB::transaction(function () use (
                $academicYearId, $classLevelId, $streamId, $subjectId, $coefficient,
                $requiredComponents, $subjectGroupId, $maxScoreOverride, $isOptional,
                $countsTowardAverage, $effectiveFromPeriodId, $effectiveToPeriodId
            ): SubjectAllocation {
                $allocation = SubjectAllocation::query()->create([
                    'academic_year_id' => $academicYearId,
                    'class_level_id' => $classLevelId,
                    'stream_id' => $streamId ?? SubjectAllocation::STREAM_NONE,
                    'subject_id' => $subjectId,
                    'coefficient' => $coefficient,
                    'subject_group_id' => $subjectGroupId,
                    'required_components' => $requiredComponents,
                    'max_score_override' => $maxScoreOverride,
                    'is_optional' => $isOptional,
                    'counts_toward_average' => $countsTowardAverage,
                    'effective_from_period_id' => $effectiveFromPeriodId,
                    'effective_to_period_id' => $effectiveToPeriodId,
                    'is_active' => true,
                    'version' => 1,
                ]);

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Academics',
                    auditableType: SubjectAllocation::class,
                    auditableId: (int) $allocation->getKey(),
                    after: [
                        'academic_year_id' => $academicYearId,
                        'class_level_id' => $classLevelId,
                        'stream_id' => $streamId ?? SubjectAllocation::STREAM_NONE,
                        'subject_id' => $subjectId,
                        'coefficient' => $coefficient,
                    ],
                    actor: auth()->user()?->toAuditActor() ?? Actor::system(),
                );

                return $allocation;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException(
                'This subject is already allocated to this level/stream for this year.'
            );
        }
    }
}
