<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\InsuranceCoverType;
use App\Modules\Welfare\Domain\InsurancePolicyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An insurance contract (design doc §14): student group cover with a
 * per-head premium, or asset cover over a Phase 9 register item. The
 * premium BILLS through the linked FeeItem like any other fee - this model
 * never posts anything. asset_id and fee_item_id are bare ids: those rows
 * belong to other modules, read via DB::table inside Actions only
 * (ModuleBoundaryTest).
 *
 * @property int $id
 * @property string $provider
 * @property string $policy_no
 * @property InsuranceCoverType $cover_type
 * @property int|null $premium_per_student
 * @property Carbon $coverage_start
 * @property Carbon $coverage_end
 * @property int $academic_year_id
 * @property int|null $asset_id
 * @property int|null $fee_item_id
 * @property InsurancePolicyStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class InsurancePolicy extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'provider', 'policy_no', 'cover_type', 'premium_per_student',
        'coverage_start', 'coverage_end', 'academic_year_id',
        'asset_id', 'fee_item_id', 'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cover_type' => InsuranceCoverType::class,
            'premium_per_student' => 'integer',
            'coverage_start' => 'date',
            'coverage_end' => 'date',
            'academic_year_id' => 'integer',
            'asset_id' => 'integer',
            'fee_item_id' => 'integer',
            'status' => InsurancePolicyStatus::class,
        ];
    }

    /**
     * @return HasMany<StudentInsurance, $this>
     */
    public function studentInsurances(): HasMany
    {
        return $this->hasMany(StudentInsurance::class, 'policy_id');
    }

    /**
     * @return HasMany<InsuranceClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class, 'policy_id');
    }

    /** Does the coverage period contain the given date? */
    public function covers(Carbon $date): bool
    {
        return ! $date->lessThan($this->coverage_start)
            && ! $date->greaterThan($this->coverage_end);
    }
}
