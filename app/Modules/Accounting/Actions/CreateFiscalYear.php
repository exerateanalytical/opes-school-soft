<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 02-accounting §6, `ValidateFiscalYearDates` folded into the one Action
 * this phase owns. Creates the year AND its twelve (or, for an irregular
 * first exercice, fewer) AccountingPeriod rows in the same transaction -
 * §5.1: "Periods are generated for all 12 months when a FiscalYear is
 * created, so no month is ever missing."
 */
final class CreateFiscalYear
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(string $code, string $startsOn, string $endsOn, bool $isFirstExercice, Actor $actor): FiscalYear
    {
        Gate::authorize(self::PERMISSION);

        $starts = Carbon::parse($startsOn)->startOfDay();
        $ends = Carbon::parse($endsOn)->startOfDay();

        $this->validateDates($starts, $ends, $isFirstExercice);

        return DB::transaction(function () use ($code, $starts, $ends, $isFirstExercice, $actor): FiscalYear {
            // Lock the latest year so two concurrent creations cannot both
            // pass the contiguity/overlap check against the same tail -
            // mirrors CreateAcademicYear (00-core §8's pattern applied here
            // to fiscal years, which §6 also requires to be contiguous).
            $latest = FiscalYear::query()
                ->orderByDesc('ends_on')
                ->lockForUpdate()
                ->first();

            if ($latest !== null) {
                if ($starts->lessThanOrEqualTo($latest->ends_on)) {
                    throw new DomainException(sprintf(
                        'Fiscal year %s (%s to %s) overlaps %s, which ends %s.',
                        $code,
                        $starts->toDateString(),
                        $ends->toDateString(),
                        $latest->code,
                        $latest->ends_on->toDateString(),
                    ));
                }

                $expected = $latest->ends_on->copy()->addDay();

                if (! $starts->isSameDay($expected)) {
                    throw new DomainException(sprintf(
                        'Fiscal years must be contiguous and gapless: the next year must start on %s, the day after %s ends (%s). Got %s.',
                        $expected->toDateString(),
                        $latest->code,
                        $latest->ends_on->toDateString(),
                        $starts->toDateString(),
                    ));
                }
            }

            $year = FiscalYear::query()->create([
                'code' => $code,
                'starts_on' => $starts->toDateString(),
                'ends_on' => $ends->toDateString(),
                'status' => FiscalYearStatus::Planned,
                'is_first_exercice' => $isFirstExercice,
            ]);

            $this->generatePeriods($year, $starts, $ends);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: FiscalYear::class,
                auditableId: (int) $year->getKey(),
                after: [
                    'code' => $code,
                    'starts_on' => $starts->toDateString(),
                    'ends_on' => $ends->toDateString(),
                    'is_first_exercice' => $isFirstExercice,
                ],
                actor: $actor,
            );

            return $year;
        });
    }

    private function validateDates(Carbon $starts, Carbon $ends, bool $isFirstExercice): void
    {
        if ($ends->lessThanOrEqualTo($starts)) {
            throw new DomainException(sprintf(
                'A fiscal year must end after it starts: got %s to %s.',
                $starts->toDateString(),
                $ends->toDateString(),
            ));
        }

        // 02-accounting §0 "Exercice" row / §6: the exercice coincides with
        // the calendar year unless this is the (permitted) irregular first
        // exercice, in which case ends_on must still be 31 December and the
        // product refuses more than 12 months.
        // Carbon::create() is typed to allow a null return for an invalid
        // calendar date, even though 12/31 and 1/1 can never actually be
        // one - Carbon::parse() of a formatted string has no such nullable
        // escape hatch, so it is what the comparison below is typed against.
        if (! $ends->isSameDay(Carbon::parse(sprintf('%d-12-31', $ends->year)))) {
            throw new DomainException('A fiscal year must end on 31 December.');
        }

        if (! $isFirstExercice) {
            if (! $starts->isSameDay(Carbon::parse(sprintf('%d-01-01', $starts->year)))) {
                throw new DomainException('A fiscal year must start on 1 January unless it is the irregular first exercice.');
            }

            if ($starts->year !== $ends->year) {
                throw new DomainException('A fiscal year must start and end within the same calendar year.');
            }

            return;
        }

        if ($starts->year !== $ends->year) {
            throw new DomainException('The first exercice must not exceed 12 months; consult your accountant.');
        }
    }

    private function generatePeriods(FiscalYear $year, Carbon $starts, Carbon $ends): void
    {
        $cursor = $starts->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($ends)) {
            $periodStarts = $cursor->copy()->max($starts);
            $periodEnds = $cursor->copy()->endOfMonth()->min($ends);
            $isQuarterEnd = in_array((int) $cursor->format('n'), [3, 6, 9, 12], true);

            AccountingPeriod::query()->create([
                'fiscal_year_id' => $year->getKey(),
                'period_month' => $cursor->copy()->startOfMonth()->toDateString(),
                'starts_on' => $periodStarts->toDateString(),
                'ends_on' => $periodEnds->toDateString(),
                'status' => 'open',
                'is_quarter_end' => $isQuarterEnd,
                // §5.3: last day of the month following quarter end, the
                // conservative default the settings screen labels as such.
                'forced_closure_due_on' => $isQuarterEnd
                    ? $cursor->copy()->addMonthNoOverflow()->endOfMonth()->toDateString()
                    : null,
            ]);

            $cursor->addMonthNoOverflow();
        }
    }
}
