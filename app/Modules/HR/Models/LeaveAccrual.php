<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\LeaveEntryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One signed delta on the append-only leave ledger
 * (docs/specs/05-hr-payroll.md 12, fixing H8). BEFORE UPDATE / BEFORE
 * DELETE triggers reject every mutation at the database, so this model is
 * insert-only by construction; balance is ALWAYS the SUM, never a column.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property int $leave_type_id
 * @property LeaveEntryType $entry_type
 * @property numeric-string $delta_days
 * @property Carbon $effective_on
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property string|null $accrual_month_key
 */
final class LeaveAccrual extends Model
{
    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'leave_type_id',
        'entry_type',
        'delta_days',
        'effective_on',
        'source_type',
        'source_id',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => LeaveEntryType::class,
            'delta_days' => 'decimal:2',
            'effective_on' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The one true balance (12.2): SELECT SUM(delta_days), optionally as it
     * stood on a date - the question a mutable column can never answer.
     */
    public static function balance(int $staffContractId, int $leaveTypeId, ?string $asOf = null): string
    {
        $query = self::query()
            ->where('staff_contract_id', $staffContractId)
            ->where('leave_type_id', $leaveTypeId);

        if ($asOf !== null) {
            $query->where('effective_on', '<=', $asOf);
        }

        $sum = $query->sum(DB::raw('delta_days'));

        return number_format((float) $sum, 2, '.', '');
    }

    /**
     * @return BelongsTo<StaffContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(StaffContract::class, 'staff_contract_id');
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForContract(Builder $query, int $staffContractId): Builder
    {
        return $query->where('staff_contract_id', $staffContractId);
    }
}
