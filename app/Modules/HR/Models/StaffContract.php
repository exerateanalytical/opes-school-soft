<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\ContractType;
use App\Modules\HR\Domain\SocialSecurityStatus;
use App\Modules\HR\Domain\TerminationReason;
use App\Modules\HR\Domain\WorkingTime;
use Database\Factories\StaffContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The single source of employment truth (docs/specs/05-hr-payroll.md 3.4).
 *
 * Effective-dated and POSSIBLY CONCURRENT: one person may hold a teaching
 * contract and a boarding-master contract simultaneously, so the "one active
 * X" pattern applies per `contract_role` (the `active_role_key` generated
 * column), not per staff member.
 *
 * `department_id`, `position_id`, `salary_grade_id` and
 * `collective_agreement_id` are plain FK attributes; Department belongs to
 * Academics and is read cross-module via DB::table only (00-core 6.2).
 *
 * @property int $id
 * @property int $staff_member_id
 * @property string $contract_role
 * @property ContractType $contract_type
 * @property WorkingTime $working_time
 * @property int $department_id
 * @property int $position_id
 * @property int|null $salary_grade_id
 * @property int|null $collective_agreement_id
 * @property string|null $category
 * @property string|null $echelon
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $probation_end
 * @property int $renewal_count
 * @property int|null $renewed_from_contract_id
 * @property Carbon|null $converted_to_cdi_on
 * @property string|null $mintss_visa_ref
 * @property SocialSecurityStatus $social_security_status
 * @property bool $is_payroll_eligible
 * @property string|null $rp_risk_class_override
 * @property string|null $override_reason
 * @property Carbon $seniority_reference_date
 * @property TerminationReason|null $termination_reason
 * @property string|null $active_role_key
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffContract extends Model
{
    /** @use HasFactory<StaffContractFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'staff_member_id',
        'contract_role',
        'contract_type',
        'working_time',
        'department_id',
        'position_id',
        'salary_grade_id',
        'collective_agreement_id',
        'category',
        'echelon',
        'starts_on',
        'ends_on',
        'probation_end',
        'renewal_count',
        'renewed_from_contract_id',
        'converted_to_cdi_on',
        'mintss_visa_ref',
        'social_security_status',
        'is_payroll_eligible',
        'rp_risk_class_override',
        'override_reason',
        'seniority_reference_date',
        'termination_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contract_type' => ContractType::class,
            'working_time' => WorkingTime::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'probation_end' => 'date',
            'renewal_count' => 'integer',
            'converted_to_cdi_on' => 'date',
            'social_security_status' => SocialSecurityStatus::class,
            'is_payroll_eligible' => 'boolean',
            'seniority_reference_date' => 'date',
            'termination_reason' => TerminationReason::class,
            'version' => 'integer',
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
     * @return HasMany<StaffContractExemption, $this>
     */
    public function exemptions(): HasMany
    {
        return $this->hasMany(StaffContractExemption::class);
    }

    /**
     * @return HasMany<StaffCostAllocation, $this>
     */
    public function costAllocations(): HasMany
    {
        return $this->hasMany(StaffCostAllocation::class);
    }

    /**
     * @return BelongsTo<StaffContract, $this>
     */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_contract_id');
    }

    /**
     * In force on the given date: [starts_on, ends_on), ends_on exclusive
     * like every effective-dated range in this codebase.
     */
    public function coversDate(string $date): bool
    {
        $day = Carbon::parse($date);

        if ($day->lt($this->starts_on)) {
            return false;
        }

        return $this->ends_on === null || $day->lt($this->ends_on);
    }

    /**
     * @param  Builder<StaffContract>  $query
     * @return Builder<StaffContract>
     */
    public function scopeInForceOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('starts_on', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('ends_on')->orWhere('ends_on', '>', $date);
            });
    }

    protected static function newFactory(): StaffContractFactory
    {
        return StaffContractFactory::new();
    }
}
