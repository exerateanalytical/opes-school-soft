<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\CashDeskSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/04-fees.md §11.7 - one cashier's shift on one cash box.
 *
 * A thin record on purpose: the arithmetic lives in OpenCashDeskSession /
 * CloseCashDeskSession, and `expected_cash` is RECOMPUTED from the session's
 * own payments at close rather than incremented per collection. A running
 * total maintained by the collection path is a cache, and a cache is exactly
 * what you must not trust when the question is "did money go missing".
 *
 * @property int $id
 * @property string $session_no
 * @property int $treasury_account_id
 * @property Carbon $business_date
 * @property int $opened_by
 * @property Carbon $opened_at
 * @property int $opening_float
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property int|null $expected_cash
 * @property int|null $counted_cash
 * @property int|null $variance
 * @property string|null $variance_reason
 * @property CashDeskSessionStatus $status
 * @property int|null $journal_entry_id
 * @property string|null $notes
 */
final class CashDeskSession extends Model
{
    protected $table = 'cash_desk_sessions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'variance' => 'integer',
            'status' => CashDeskSessionStatus::class,
        ];
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cash_desk_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === CashDeskSessionStatus::Open;
    }

    /** A shortage is negative, an overage positive (02-accounting §11.5). */
    public function isShortage(): bool
    {
        return ($this->variance ?? 0) < 0;
    }
}
