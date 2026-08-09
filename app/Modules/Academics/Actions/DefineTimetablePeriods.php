<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\TimetablePeriod;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Replace a section's bell schedule wholesale — "Add New Period" and "Set
 * Time Breaks" on the mockup are both edits to this one ordered list (09-ui
 * §8.6). Durations are school-entered, never seeded (09-ui open question 3).
 *
 * Replacement, not patching: the grid is meaningful only as a whole (ordered,
 * non-overlapping, gap-free enough to render), so the Action validates the
 * complete list and swaps it atomically. Once slots reference the old
 * periods the FK RESTRICT refuses the swap — clear the slots first; silently
 * re-pointing cells at renumbered periods would rewrite the timetable.
 */
final class DefineTimetablePeriods
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array{name: string, starts_at: string, ends_at: string, is_break?: bool, name_fr?: string|null}>  $periods
     * @return list<TimetablePeriod>
     */
    public function handle(int $schoolSectionId, array $periods): array
    {
        Gate::authorize(Permission::TimetableManage->value);

        SchoolSection::query()->findOrFail($schoolSectionId);

        if ($periods === []) {
            throw ValidationException::withMessages([
                'periods' => 'A bell schedule needs at least one period.',
            ]);
        }

        $rows = $this->validated($periods);

        try {
            return DB::transaction(function () use ($schoolSectionId, $rows): array {
                TimetablePeriod::query()
                    ->where('school_section_id', $schoolSectionId)
                    ->delete();

                $created = [];

                foreach ($rows as $index => $row) {
                    $created[] = TimetablePeriod::query()->create([
                        'school_section_id' => $schoolSectionId,
                        'name' => $row['name'],
                        'name_fr' => $row['name_fr'],
                        'sequence' => $index + 1,
                        'starts_at' => $row['starts_at'],
                        'ends_at' => $row['ends_at'],
                        'is_break' => $row['is_break'],
                        'duration_minutes' => $row['duration_minutes'],
                    ]);
                }

                $this->audit->handle(
                    action: AuditAction::Updated,
                    module: 'Academics',
                    auditableType: TimetablePeriod::class,
                    auditableId: null,
                    after: [
                        'school_section_id' => $schoolSectionId,
                        'periods' => array_map(static fn (array $row): string => $row['name']
                            .' '.$row['starts_at'].'-'.$row['ends_at'], $rows),
                    ],
                    actor: auth()->user()?->toAuditActor() ?? Actor::system(),
                );

                return $created;
            });
        } catch (QueryException $exception) {
            // FK RESTRICT from timetable_slots.timetable_period_id: 1451 is
            // MySQL's "cannot delete, a foreign key holds this row".
            if (str_contains($exception->getMessage(), 'a foreign key constraint fails')) {
                throw new DomainException(
                    'This section\'s timetable already has assigned slots; '
                    .'remove them before redefining the bell schedule.'
                );
            }

            throw $exception;
        }
    }

    /**
     * @param  list<array{name: string, starts_at: string, ends_at: string, is_break?: bool, name_fr?: string|null}>  $periods
     * @return list<array{name: string, name_fr: string|null, starts_at: string, ends_at: string, is_break: bool, duration_minutes: int}>
     */
    private function validated(array $periods): array
    {
        $rows = [];
        $previousEnd = null;

        foreach ($periods as $index => $period) {
            $name = trim($period['name']);

            if ($name === '') {
                throw ValidationException::withMessages([
                    'periods' => 'Period '.($index + 1).' has no name.',
                ]);
            }

            $starts = $this->time($period['starts_at'], $index);
            $ends = $this->time($period['ends_at'], $index);

            if ($ends->lte($starts)) {
                throw ValidationException::withMessages([
                    'periods' => "\"{$name}\" ends at or before it starts — a period must have positive length.",
                ]);
            }

            if ($previousEnd !== null && $starts->lt($previousEnd)) {
                throw ValidationException::withMessages([
                    'periods' => "\"{$name}\" overlaps the previous period; the bell schedule must be in order and non-overlapping.",
                ]);
            }

            $previousEnd = $ends;

            $rows[] = [
                'name' => $name,
                'name_fr' => $period['name_fr'] ?? null,
                'starts_at' => $starts->format('H:i:s'),
                'ends_at' => $ends->format('H:i:s'),
                'is_break' => (bool) ($period['is_break'] ?? false),
                'duration_minutes' => (int) $starts->diffInMinutes($ends),
            ];
        }

        return $rows;
    }

    private function time(string $value, int $index): Carbon
    {
        try {
            return Carbon::parse('2000-01-01 '.trim($value));
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'periods' => 'Period '.($index + 1)." has an unreadable time \"{$value}\"; use HH:MM.",
            ]);
        }
    }
}
