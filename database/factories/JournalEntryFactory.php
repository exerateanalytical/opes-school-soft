<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds a JournalEntry against a self-consistent calendar: a FiscalYear
 * spanning the calendar year, an open AccountingPeriod for the chosen
 * month, and an AcademicYear row wide enough to cover the same date. The
 * migration's trg_je_derive_calendar_before_insert trigger (L6/C3)
 * re-derives all three from `date` and SIGNALs on any mismatch, so this
 * factory must get them right or every test using it fails at the INSERT,
 * not later.
 *
 * The AcademicYear row is inserted with `DB::table()`, never through
 * `App\Modules\Academics\Models\AcademicYear` - that import is what
 * tests/Architecture/ModuleBoundaryTest.php forbids for app code, and this
 * factory follows the same discipline `EnrollmentFactory` uses for
 * cross-team prerequisite rows so it does not depend on another module's
 * factory to stay green.
 *
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    /** @var class-string<JournalEntry> */
    protected $model = JournalEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2030, 2399);
        $month = fake()->numberBetween(1, 12);
        $date = Carbon::createFromDate($year, $month, 15);

        $calendar = $this->buildCalendar($date);

        return [
            'journal_id' => Journal::factory(),
            'piece_no' => null,
            'date' => $date->toDateString(),
            'value_date' => $date->toDateString(),
            'accounting_period_id' => $calendar['accounting_period_id'],
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'academic_year_id' => $calendar['academic_year_id'],
            'label' => fake()->sentence(4),
            'reference' => null,
            'status' => JournalEntry::STATUS_DRAFT,
            'total_debit' => 0,
            'total_credit' => 0,
        ];
    }

    /**
     * Builds a fiscal year, its containing accounting period, and an
     * academic year - all covering `$date` - and returns their ids.
     *
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    public function buildCalendar(Carbon $date): array
    {
        $year = (int) $date->format('Y');

        $fiscalYear = FiscalYear::factory()->create([
            'code' => strtoupper(Str::random(8)),
            'starts_on' => Carbon::createFromDate($year, 1, 1)->toDateString(),
            'ends_on' => Carbon::createFromDate($year, 12, 31)->toDateString(),
            'status' => FiscalYearStatus::Open,
        ]);

        $period = AccountingPeriod::factory()->create([
            'fiscal_year_id' => $fiscalYear->getKey(),
            'period_month' => $date->copy()->startOfMonth()->toDateString(),
            'starts_on' => $date->copy()->startOfMonth()->toDateString(),
            'ends_on' => $date->copy()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::Open,
        ]);

        $academicYearId = DB::table('academic_years')->insertGetId([
            'code' => 'AY-'.$year.'-'.Str::random(8),
            'name' => 'Academic Year covering '.$date->toDateString(),
            'starts_on' => Carbon::createFromDate($year, 1, 1)->toDateString(),
            'ends_on' => Carbon::createFromDate($year, 12, 31)->toDateString(),
            'is_current' => false,
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'fiscal_year_id' => (int) $fiscalYear->getKey(),
            'accounting_period_id' => (int) $period->getKey(),
            'academic_year_id' => (int) $academicYearId,
        ];
    }
}
