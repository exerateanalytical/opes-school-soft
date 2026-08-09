<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\InstallmentBasis;
use Database\Factories\InstallmentPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 04-fees.md §2.6. `fee_structure_id` uses the NOT NULL sentinel 0 for
 * "global plan" so the one-default-per-(academic_year, structure) generated
 * UNIQUE cannot be defeated by MySQL's duplicate-NULL behaviour. The sum
 * constraint (Σ percentage_bp = 1 000 000) is enforced in
 * SaveInstallmentPlan, never here.
 *
 * @property int $id
 * @property int $academic_year_id
 * @property string $name
 * @property int $fee_structure_id 0 = global (sentinel)
 * @property InstallmentBasis $basis
 * @property bool $is_default
 * @property string|null $default_scope_key generated
 */
final class InstallmentPlan extends Model
{
    /** @use HasFactory<InstallmentPlanFactory> */
    use HasFactory;

    public const GLOBAL = 0;

    /** @var list<string> */
    protected $fillable = [
        'academic_year_id', 'name', 'fee_structure_id', 'basis', 'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_year_id' => 'integer',
            'fee_structure_id' => 'integer',
            'basis' => InstallmentBasis::class,
            'is_default' => 'boolean',
        ];
    }

    protected static function newFactory(): InstallmentPlanFactory
    {
        return InstallmentPlanFactory::new();
    }

    /**
     * @return HasMany<InstallmentPlanLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InstallmentPlanLine::class)->orderBy('sequence_no');
    }
}
