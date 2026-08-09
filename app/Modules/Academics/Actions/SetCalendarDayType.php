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
 * Retype one calendar date — the door through which the school enters its
 * public holidays, exam days and closures (07-students §9.2: holidays are
 * entered, never seeded). A section-specific row shadows the all-sections row
 * for that date; ResolveCalendarDay resolves specific-over-all.
 */
final class SetCalendarDayType
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        int $academicYearId,
        string $date,
        CalendarDayType $dayType,
        ?int $schoolSectionId = null,
        ?string $label = null,
        ?string $labelFr = null,
    ): SchoolCalendarDay {
        Gate::authorize(Permission::CalendarManage->value);

        /** @var AcademicYear $year */
        $year = AcademicYear::query()->findOrFail($academicYearId);

        $day = Carbon::parse($date)->startOfDay();

        if ($day->lt($year->starts_on) || $day->gt($year->ends_on)) {
            throw ValidationException::withMessages([
                'date' => 'The date '.$day->toDateString().' lies outside academic year '
                    .$year->code.' ('.$year->starts_on->toDateString()
                    .' – '.$year->ends_on->toDateString().').',
            ]);
        }

        $sectionSentinel = $schoolSectionId ?? SchoolCalendarDay::SECTION_ALL;

        if ($sectionSentinel !== SchoolCalendarDay::SECTION_ALL
            && ! SchoolSection::query()->whereKey($sectionSentinel)->exists()) {
            // The sentinel column carries no FK; the Action enforces it.
            throw ValidationException::withMessages([
                'school_section_id' => 'The selected school section does not exist.',
            ]);
        }

        return DB::transaction(function () use (
            $year, $day, $dayType, $sectionSentinel, $label, $labelFr
        ): SchoolCalendarDay {
            $existing = SchoolCalendarDay::query()
                ->where('academic_year_id', $year->getKey())
                ->whereDate('date', $day->toDateString())
                ->where('school_section_id', $sectionSentinel)
                ->lockForUpdate()
                ->first();

            $before = $existing === null ? null : [
                'day_type' => $existing->day_type->value,
                'label' => $existing->label,
            ];

            if ($existing === null) {
                $calendarDay = SchoolCalendarDay::query()->create([
                    'academic_year_id' => (int) $year->getKey(),
                    'date' => $day->toDateString(),
                    'day_type' => $dayType,
                    'school_section_id' => $sectionSentinel,
                    'label' => $label,
                    'label_fr' => $labelFr,
                ]);
            } else {
                $existing->day_type = $dayType;
                $existing->label = $label;
                $existing->label_fr = $labelFr;
                $existing->save();
                $calendarDay = $existing;
            }

            $this->audit->handle(
                action: $before === null ? AuditAction::Created : AuditAction::Updated,
                module: 'Academics',
                auditableType: SchoolCalendarDay::class,
                auditableId: (int) $calendarDay->getKey(),
                before: $before,
                after: [
                    'date' => $day->toDateString(),
                    'day_type' => $dayType->value,
                    'school_section_id' => $sectionSentinel,
                    'label' => $label,
                ],
                actor: auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $calendarDay;
        });
    }
}
