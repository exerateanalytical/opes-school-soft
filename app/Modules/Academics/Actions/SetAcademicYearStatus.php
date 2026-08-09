<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Domain\AcademicYearStatus;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Moves an academic year through its lifecycle (00-core §8: planned → active
 * → closed). The Academics-owned door the rollover wizard's step 10 uses to
 * activate the new year and - when the outgoing year's periods are published
 * and its balances settled or carried - close the outgoing one
 * (docs/specs/08-operations.md §6.2 step 10). Undo uses it in reverse.
 *
 * WHEN a year may close is deliberately not decided here: the eligibility
 * rules (§6.2 step 10 guard) belong to the caller that can see the whole
 * picture (the rollover engine). This door is the mechanism, audited, and a
 * no-op when the status already matches.
 */
final class SetAcademicYearStatus
{
    /** See CreateAcademicYear::PERMISSION for why this is a raw string. */
    public const PERMISSION = CreateAcademicYear::PERMISSION;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $academicYearId, string $status, Actor $actor): AcademicYear
    {
        Gate::authorize(self::PERMISSION);

        $target = AcademicYearStatus::from($status);

        return DB::transaction(function () use ($academicYearId, $target, $actor): AcademicYear {
            /** @var AcademicYear $year */
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($academicYearId);

            if ($year->status === $target) {
                return $year;
            }

            $before = $year->status;
            $year->status = $target;
            $year->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Academics',
                auditableType: AcademicYear::class,
                auditableId: (int) $year->getKey(),
                before: ['status' => $before->value],
                after: ['status' => $target->value],
                actor: $actor,
            );

            return $year;
        });
    }
}
