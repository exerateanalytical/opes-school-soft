<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\BudgetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §16. Persistence only - B-1/B-2/B-3 are
 * enforced by the migration's keys and CHECKs and by the budget Actions under
 * lock, never by model events (same rule as JournalEntry).
 *
 * `current_fiscal_year_key` is a STORED generated column and therefore never
 * appears in `$fillable`.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property string $code
 * @property string $name
 * @property BudgetStatus $status
 * @property int $version
 * @property bool $is_current
 * @property int|null $current_fiscal_year_key
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $notes
 */
final class Budget extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'fiscal_year_id',
        'academic_year_id',
        'code',
        'name',
        'status',
        'version',
        'is_current',
        'approved_by',
        'approved_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BudgetStatus::class,
            'version' => 'integer',
            'is_current' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
