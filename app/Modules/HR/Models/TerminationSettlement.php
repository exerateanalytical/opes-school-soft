<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\SettlementStatus;
use App\Modules\HR\Domain\TerminationReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/05-hr-payroll.md 13.1 (H9). ONE per contract, forever.
 * `indemnite_licenciement` is manual-with-basis-note while the severance
 * schedule is NEEDS VERIFICATION; the exempt/taxable IRPP split likewise.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property TerminationReason $termination_type
 * @property Carbon|null $notice_start
 * @property Carbon|null $notice_end
 * @property bool $notice_served
 * @property Carbon $last_working_day
 * @property Carbon|null $settlement_date
 * @property numeric-string $seniority_years
 * @property int|null $indemnite_licenciement
 * @property string|null $indemnite_basis_note
 * @property int|null $indemnite_compensatrice_preavis
 * @property int|null $leave_compensation
 * @property array<string, mixed>|null $other_amounts
 * @property int|null $exempt_portion
 * @property int|null $taxable_portion
 * @property SettlementStatus $status
 * @property int|null $approved_by
 * @property int|null $payroll_run_id
 * @property int|null $certificat_travail_document_id
 * @property int|null $solde_de_tout_compte_document_id
 * @property int $created_by
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TerminationSettlement extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'termination_type',
        'notice_start',
        'notice_end',
        'notice_served',
        'last_working_day',
        'settlement_date',
        'seniority_years',
        'indemnite_licenciement',
        'indemnite_basis_note',
        'indemnite_compensatrice_preavis',
        'leave_compensation',
        'other_amounts',
        'exempt_portion',
        'taxable_portion',
        'status',
        'approved_by',
        'payroll_run_id',
        'created_by',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'termination_type' => TerminationReason::class,
            'notice_start' => 'date',
            'notice_end' => 'date',
            'notice_served' => 'boolean',
            'last_working_day' => 'date',
            'settlement_date' => 'date',
            'seniority_years' => 'decimal:2',
            'indemnite_licenciement' => 'integer',
            'indemnite_compensatrice_preavis' => 'integer',
            'leave_compensation' => 'integer',
            'other_amounts' => 'array',
            'exempt_portion' => 'integer',
            'taxable_portion' => 'integer',
            'status' => SettlementStatus::class,
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
}
