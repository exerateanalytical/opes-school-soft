<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/05-hr-payroll.md 3.7. Keyed on BOTH the person and the contract,
 * for the same reason student discipline is keyed on both: the sanction
 * ladder is a property of the person, the year filter a property of the
 * contract.
 *
 * @property int $id
 * @property int $staff_member_id
 * @property int $staff_contract_id
 * @property string $case_ref
 * @property Carbon $opened_on
 * @property string|null $sanction
 * @property int|null $document_id
 * @property Carbon|null $closed_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffDisciplineCase extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_member_id',
        'staff_contract_id',
        'case_ref',
        'opened_on',
        'sanction',
        'document_id',
        'closed_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_on' => 'date',
            'document_id' => 'integer',
            'closed_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<StaffMember, $this>
     */
    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    /**
     * @return BelongsTo<StaffContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(StaffContract::class, 'staff_contract_id');
    }
}
