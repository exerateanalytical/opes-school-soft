<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/05-hr-payroll.md 12.2. Approval writes ONE `taken` ledger row;
 * cancellation writes a compensating `adjustment` row - the request status
 * moves, the ledger only ever grows.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property int $leave_type_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property numeric-string $working_days
 * @property LeaveRequestStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejection_reason
 * @property int|null $medical_certificate_document_id
 * @property int|null $replacement_staff_contract_id
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class LeaveRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'leave_type_id',
        'starts_on',
        'ends_on',
        'working_days',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'medical_certificate_document_id',
        'replacement_staff_contract_id',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'working_days' => 'decimal:2',
            'status' => LeaveRequestStatus::class,
            'approved_at' => 'datetime',
            'version' => 'integer',
        ];
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
}
