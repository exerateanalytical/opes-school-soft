<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Domain\CnpsClaimStatus;
use App\Modules\Payroll\Domain\CnpsClaimType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The employer's reimbursement receivable for advanced CNPS benefits
 * (docs/specs/05-hr-payroll.md 11.6). `journal_entry_id` stays NULL until
 * 02-accounting confirms the CNPS-receivable sub-account (NEEDS
 * VERIFICATION): the entity and its ageing exist now, the posting is
 * withheld.
 *
 * @property int $id
 * @property int $staff_member_id
 * @property CnpsClaimType $claim_type
 * @property int|null $work_accident_id
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property int $amount_advanced
 * @property int $amount_claimed
 * @property int $amount_reimbursed
 * @property Carbon|null $submitted_at
 * @property string|null $cnps_reference
 * @property CnpsClaimStatus $status
 * @property int|null $journal_entry_id
 * @property int $created_by
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CnpsBenefitClaim extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_member_id',
        'claim_type',
        'work_accident_id',
        'period_from',
        'period_to',
        'amount_advanced',
        'amount_claimed',
        'amount_reimbursed',
        'submitted_at',
        'cnps_reference',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'claim_type' => CnpsClaimType::class,
            'period_from' => 'date',
            'period_to' => 'date',
            'amount_advanced' => 'integer',
            'amount_claimed' => 'integer',
            'amount_reimbursed' => 'integer',
            'submitted_at' => 'datetime',
            'status' => CnpsClaimStatus::class,
            'version' => 'integer',
        ];
    }
}
