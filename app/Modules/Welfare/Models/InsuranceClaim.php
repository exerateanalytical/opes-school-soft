<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\ClaimStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A claim against a policy (design doc §14): draft → submitted →
 * settled | rejected, with the schema CHECK keeping amounts and dates
 * honest per state. Settlement here is a PAPER fact - the cash receipt is
 * deferred to the treasury phase (tracked debt), and no ledger write ever
 * happens in Welfare.
 *
 * @property int $id
 * @property int $policy_id
 * @property int|null $student_insurance_id
 * @property Carbon $incident_date
 * @property string $description
 * @property int $amount_claimed
 * @property int|null $amount_settled
 * @property ClaimStatus $status
 * @property Carbon|null $settled_on
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class InsuranceClaim extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'policy_id', 'student_insurance_id', 'incident_date', 'description',
        'amount_claimed', 'amount_settled', 'status', 'settled_on', 'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'policy_id' => 'integer',
            'student_insurance_id' => 'integer',
            'incident_date' => 'date',
            'amount_claimed' => 'integer',
            'amount_settled' => 'integer',
            'status' => ClaimStatus::class,
            'settled_on' => 'date',
            'recorded_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InsurancePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'policy_id');
    }

    /**
     * @return BelongsTo<StudentInsurance, $this>
     */
    public function studentInsurance(): BelongsTo
    {
        return $this->belongsTo(StudentInsurance::class, 'student_insurance_id');
    }
}
