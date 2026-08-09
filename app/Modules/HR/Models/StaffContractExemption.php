<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\StatutoryBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A per-branch statutory exemption on one contract
 * (docs/specs/05-hr-payroll.md 3.5). Every exemption is a claim the labour
 * inspector will test: the evidencing document reference is NOT NULL, the
 * approver is recorded, and each one appears on the run's exception report.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property StatutoryBranch $branch
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $exemption_document_ref
 * @property int $approved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffContractExemption extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'branch',
        'effective_from',
        'effective_to',
        'exemption_document_ref',
        'approved_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch' => StatutoryBranch::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
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
