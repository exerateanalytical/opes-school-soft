<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Domain\CalendarDayType;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\SchoolCalendarDay;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md §9.2: every date in [starts_on, ends_on] must
 * resolve to exactly one calendar row per section. This seeds the base grid —
 * Sundays as `weekend`, everything else `teaching` (the six-day week of 09-ui
 * §8.6; Saturday teaching is a per-school reality). Public holidays are then
 * ENTERED BY THE SCHOOL through SetCalendarDayType, never seeded: the
 * Cameroonian holiday calendar includes movable feasts and needs the official
 * annual decree.
 *
 * Idempotent by insertOrIgnore against the (year, date, section) UNIQUE key —
 * re-running after the school has retyped days overwrites nothing.
 */
final class SeedSchoolCalendar
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @return int the number of calendar days actually inserted
     */
    public function handle(int $academicYearId, ?int $schoolSectionId = null): int
    {
        Gate::authorize(Permission::CalendarManage->value);

        /** @var AcademicYear $year */
        $year = AcademicYear::query()->findOrFail($academicYearId);

        $sectionSentinel = $schoolSectionId ?? SchoolCalendarDay::SECTION_ALL;

        if ($sectionSentinel !== SchoolCalendarDay::SECTION_ALL
            && ! SchoolSection::query()->whereKey($sectionSentinel)->exists()) {
            // The sentinel column carries no FK (0 must be storable), so the
            // Action is where the reference is enforced.
            throw ValidationException::withMessages([
                'school_section_id' => 'The selected school section does not exist.',
            ]);
        }

        return DB::transaction(function () use ($year, $sectionSentinel): int {
            $cursor = Carbon::parse($year->starts_on->toDateString());
            $end = Carbon::parse($year->ends_on->toDateString());
            $now = now();

            $rows = [];

            while ($cursor->lte($end)) {
                $rows[] = [
                    'academic_year_id' => (int) $year->getKey(),
                    'date' => $cursor->toDateString(),
                    'day_type' => $cursor->isSunday()
                        ? CalendarDayType::Weekend->value
                        : CalendarDayType::Teaching->value,
                    'school_section_id' => $sectionSentinel,
                    'label' => null,
                    'label_fr' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $cursor->addDay();
            }

            $inserted = 0;

            foreach (array_chunk($rows, 500) as $chunk) {
                $inserted += SchoolCalendarDay::query()->insertOrIgnore($chunk);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: SchoolCalendarDay::class,
                auditableId: null,
                after: [
                    'academic_year_id' => (int) $year->getKey(),
                    'school_section_id' => $sectionSentinel,
                    'days_inserted' => $inserted,
                    'days_in_year' => count($rows),
                ],
                actor: auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $inserted;
        });
    }
}
