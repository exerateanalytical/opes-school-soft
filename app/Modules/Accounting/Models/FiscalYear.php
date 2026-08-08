<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\FiscalYearStatus;
use Database\Factories\FiscalYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 02-accounting §6. Persistence only. Deliberately carries NO relationship
 * to Academics\AcademicYear (see the migration's comment and §7 / C3): the
 * fiscal year and academic year calendars are independent, and every
 * financial entity resolves both from its own governing date.
 *
 * @property int $id
 * @property string $code
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property FiscalYearStatus $status
 * @property bool $is_first_exercice
 * @property int|null $opening_entry_id
 * @property int|null $closing_entry_id
 * @property int|null $result_appropriation_id
 * @property int|null $prorata_de_deduction_bp
 * @property Carbon|null $dsf_filed_at
 * @property string|null $dsf_reference
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 */
final class FiscalYear extends Model
{
    /** @use HasFactory<FiscalYearFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'starts_on', 'ends_on', 'status', 'is_first_exercice',
        'opening_entry_id', 'closing_entry_id', 'result_appropriation_id',
        'prorata_de_deduction_bp', 'dsf_filed_at', 'dsf_reference',
        'closed_at', 'closed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => FiscalYearStatus::class,
            'is_first_exercice' => 'boolean',
            'dsf_filed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): FiscalYearFactory
    {
        return FiscalYearFactory::new();
    }

    /**
     * @return HasMany<AccountingPeriod, $this>
     */
    public function accountingPeriods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }
}
