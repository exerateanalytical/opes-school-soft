<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\TimesheetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The non-teaching analogue of TeachingHoursLog for hourly administrative
 * staff (docs/specs/05-hr-payroll.md 5.5): one row per contract per payroll
 * month, same lifecycle, only `hours_validated` on a `validated` row ever
 * reaches payroll.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property Carbon $payroll_month
 * @property numeric-string $hours_worked
 * @property numeric-string|null $hours_validated
 * @property TimesheetStatus $status
 * @property int|null $validated_by
 * @property Carbon|null $validated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Timesheet extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'payroll_month',
        'hours_worked',
        'hours_validated',
        'status',
        'validated_by',
        'validated_at',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'payroll_month' => 'date',
            'hours_worked' => 'decimal:2',
            'hours_validated' => 'decimal:2',
            'status' => TimesheetStatus::class,
            'validated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StaffContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(StaffContract::class, 'staff_contract_id');
    }
}
