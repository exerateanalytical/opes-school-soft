<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Moves the is_current flag in one transaction: clear the previous holder,
 * set the new one. The schema backstops the invariant - academic_years has a
 * stored generated column (current_key = 1 only when is_current = 1) under a
 * UNIQUE index, so a race that would produce two current years surfaces as a
 * constraint violation from MySQL, never as silent corruption.
 */
final class SetCurrentAcademicYear
{
    /** See CreateAcademicYear::PERMISSION for why this is a raw string. */
    public const PERMISSION = CreateAcademicYear::PERMISSION;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $academicYearId, Actor $actor): AcademicYear
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($academicYearId, $actor): AcademicYear {
            /** @var AcademicYear $year */
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($academicYearId);

            $previous = AcademicYear::query()
                ->where('is_current', true)
                ->whereKeyNot($academicYearId)
                ->lockForUpdate()
                ->first();

            if ($previous !== null) {
                $previous->is_current = false;
                $previous->save();
            }

            if (! $year->is_current) {
                $year->is_current = true;
                $year->save();
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Academics',
                auditableType: AcademicYear::class,
                auditableId: (int) $year->getKey(),
                before: ['current' => $previous?->code],
                after: ['current' => $year->code],
                actor: $actor,
            );

            return $year;
        });
    }
}
