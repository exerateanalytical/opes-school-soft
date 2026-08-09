<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\TimesheetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A teaching-hours segment for one payroll month (docs/specs/05-hr-payroll.md
 * 5.5): `hours_planned` seeded from the timetable, `hours_taught` reduced by
 * staff attendance - both PROPOSALS. Only `hours_validated` on a `validated`
 * row ever reaches payroll; the run refuses any hourly staff member whose
 * month is not fully validated.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property Carbon $payroll_month
 * @property int|null $class_group_id
 * @property int|null $subject_id
 * @property int|null $timetable_slot_id
 * @property numeric-string $hours_planned
 * @property numeric-string $hours_taught
 * @property numeric-string|null $hours_validated
 * @property TimesheetStatus $status
 * @property int|null $validated_by
 * @property Carbon|null $validated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TeachingHoursLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'payroll_month',
        'class_group_id',
        'subject_id',
        'timetable_slot_id',
        'hours_planned',
        'hours_taught',
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
            'hours_planned' => 'decimal:2',
            'hours_taught' => 'decimal:2',
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
