<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Domain\DepreciationRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Retention\Immutable10Year;

/**
 * 06-assets-stores.md §4.1. UNIQUE(fiscal_year_id, period_month) makes the
 * database the duplicate-run gate; the Actions move status with
 * conditional UPDATEs and affected-rows checks.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property int $period_month
 * @property DepreciationRunStatus $status
 * @property int $run_by
 * @property string|null $run_at
 * @property int|null $approved_by
 * @property string|null $approved_at
 * @property int|null $journal_entry_id
 * @property int $assets_processed
 * @property int $total_charge
 * @property list<array{asset_id: int|null, reason: string}>|null $exceptions_json
 * @property string|null $idempotency_key
 */
final class DepreciationRun extends Model
{
    use Immutable10Year;
    /** @var list<string> */
    protected $fillable = [
        'fiscal_year_id', 'period_month', 'status', 'run_by', 'run_at',
        'approved_by', 'approved_at', 'journal_entry_id', 'assets_processed',
        'total_charge', 'exceptions_json', 'idempotency_key',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year_id' => 'integer',
            'period_month' => 'integer',
            'status' => DepreciationRunStatus::class,
            'run_by' => 'integer',
            'approved_by' => 'integer',
            'journal_entry_id' => 'integer',
            'assets_processed' => 'integer',
            'total_charge' => 'integer',
            'exceptions_json' => 'array',
        ];
    }

    /**
     * @return HasMany<DepreciationSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(DepreciationSchedule::class);
    }
}
