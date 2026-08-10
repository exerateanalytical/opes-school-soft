<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use Database\Factories\AccountingPeriodFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\Retention\Immutable10Year;

/**
 * 02-accounting §5, the clôture informatique. This model carries the
 * mechanism L5 in §4.3's invariant table names as primary: "An entry may
 * only be posted into an open AccountingPeriod ... in-Action, under a shared
 * lock on the period row (00-core §11)."
 *
 * ---
 * CONTRACT FOR AGENT D3's `PostJournalEntry` (owns JournalEntry):
 *
 * 1. Resolve the period for the entry's `date` with `containing()`.
 * 2. If it is `hard_locked`, forward-post per §5.4: retarget to
 *    `firstOpenOnOrAfter($requestedDate)`, keep `value_date` as the
 *    original requested date, set `entry.date` to the target period's
 *    `starts_on`, and set `is_forward_posted = true`.
 * 3. Inside the SAME `DB::transaction` as the entry insert, take the
 *    SHARED lock and assert the period is postable:
 *
 *        $period = AccountingPeriod::lockForPosting($periodId);
 *        $period->assertOpenForPosting($allowSoftLocked);
 *
 *    `$allowSoftLocked` is true only for year-end Actions, or for a manual
 *    entry made by an actor holding `accounting.post_to_soft_locked`
 *    (§5.2) - operational-module postings never pass true.
 * 4. `lockForPosting()` uses `sharedLock()` (`LOCK IN SHARE MODE`), not
 *    `lockForUpdate()`: many concurrent postings may hold it at once, but
 *    it blocks against `CloseAccountingPeriod`/`OpenAccountingPeriod`
 *    (this file's Actions), which take the row `lockForUpdate()`
 *    (exclusive) - closing the check-then-act window 00-core §11
 *    requires.
 *
 * `resolveAcademicYearId()` is the same-shaped contract for
 * `JournalEntry.academic_year_id` (§7, C3): query-builder only against the
 * `academic_years` table, never `App\Modules\Academics\Models\AcademicYear`
 * (tests/Architecture/ModuleBoundaryTest.php forbids the import).
 * ---
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property Carbon $period_month
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property AccountingPeriodStatus $status
 * @property Carbon|null $soft_locked_at
 * @property int|null $soft_locked_by
 * @property Carbon|null $hard_locked_at
 * @property int|null $hard_locked_by
 * @property string|null $unlock_reason
 * @property bool $is_quarter_end
 * @property Carbon|null $forced_closure_due_on
 */
final class AccountingPeriod extends Model
{
    use Immutable10Year;
    /** @use HasFactory<AccountingPeriodFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'fiscal_year_id', 'period_month', 'starts_on', 'ends_on', 'status',
        'soft_locked_at', 'soft_locked_by', 'hard_locked_at', 'hard_locked_by',
        'unlock_reason', 'is_quarter_end', 'forced_closure_due_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => AccountingPeriodStatus::class,
            'soft_locked_at' => 'datetime',
            'hard_locked_at' => 'datetime',
            'is_quarter_end' => 'boolean',
            'forced_closure_due_on' => 'date',
        ];
    }

    protected static function newFactory(): AccountingPeriodFactory
    {
        return AccountingPeriodFactory::new();
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /** The period whose [starts_on, ends_on] contains the given date. */
    public static function containing(Carbon|string $date): ?self
    {
        $date = self::normalise($date);

        return self::query()
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->first();
    }

    /**
     * §5.4 forward-posting target: the first OPEN period whose window ends
     * on or after the requested date, ordered chronologically. Periods are
     * contiguous and gapless within a fiscal year (§5.1), so this is simply
     * "the next period from $date forward that happens to be open".
     */
    public static function firstOpenOnOrAfter(Carbon|string $date): ?self
    {
        $date = self::normalise($date);

        return self::query()
            ->where('status', AccountingPeriodStatus::Open->value)
            ->where('ends_on', '>=', $date)
            ->orderBy('starts_on')
            ->first();
    }

    /**
     * Acquire the SHARED row lock a posting Action must hold (see the class
     * contract above). Must be called inside the caller's DB::transaction.
     */
    public static function lockForPosting(int $id): self
    {
        /** @var self $period */
        $period = self::query()->whereKey($id)->sharedLock()->firstOrFail();

        return $period;
    }

    /**
     * Refuses unless this period accepts the write. Operational modules and
     * ordinary manual entries pass $allowSoftLocked = false; year-end
     * Actions, and a manual entry made under `accounting.post_to_soft_locked`
     * (§5.2), pass true.
     */
    public function assertOpenForPosting(bool $allowSoftLocked = false): void
    {
        $ok = $this->status === AccountingPeriodStatus::Open
            || ($allowSoftLocked && $this->status === AccountingPeriodStatus::SoftLocked);

        if (! $ok) {
            throw new DomainException(sprintf(
                'AccountingPeriod %s (%s) is %s and does not accept postings.',
                $this->getKey(),
                $this->period_month->toDateString(),
                $this->status->value,
            ));
        }
    }

    /**
     * §7 (C3): the academic year a governing date falls in. 00-core §8
     * guarantees academic years are gapless-contiguous, so exactly one row
     * matches - a miss means that guarantee was violated elsewhere, which is
     * a defect worth failing loudly on rather than silently swallowing.
     *
     * Query-builder only against the `academic_years` table: Accounting must
     * never import App\Modules\Academics\Models\AcademicYear
     * (tests/Architecture/ModuleBoundaryTest.php).
     */
    public static function resolveAcademicYearId(Carbon|string $date): int
    {
        $date = self::normalise($date);

        $id = DB::table('academic_years')
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->value('id');

        if ($id === null) {
            throw new DomainException(
                "No academic year covers {$date}; 00-core §8's gapless-contiguity guarantee should make this impossible."
            );
        }

        return (int) $id;
    }

    private static function normalise(Carbon|string $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();
    }
}
