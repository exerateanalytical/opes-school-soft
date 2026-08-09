<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\InsuranceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One student's cover under a policy: enrollment × policy (design doc §14),
 * keyed on enrollment_id (07-students line 39) because cover is a fact
 * about a student's YEAR. UNIQUE(enrollment_id, policy_id) is the
 * bulk-enrolment idempotency key - re-running EnrollStudentsInPolicy can
 * never double-cover anyone.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $policy_id
 * @property Carbon $enrolled_on
 * @property string|null $certificate_no
 * @property InsuranceStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StudentInsurance extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enrollment_id', 'policy_id', 'enrolled_on', 'certificate_no', 'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_id' => 'integer',
            'policy_id' => 'integer',
            'enrolled_on' => 'date',
            'status' => InsuranceStatus::class,
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
     * @param  Builder<StudentInsurance>  $query
     * @return Builder<StudentInsurance>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '=', InsuranceStatus::Active->value);
    }
}
