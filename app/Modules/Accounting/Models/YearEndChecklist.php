<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\YearEndChecklistStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §17.3. Persistence only: YE-1..YE-4 are
 * enforced by the year-end Actions under `FOR UPDATE` and by the CHECKs in
 * 2026_08_10_360001, never by model events - the same rule the rest of this
 * module follows (see JournalEntry's docblock).
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property YearEndChecklistStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 */
final class YearEndChecklist extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'fiscal_year_id', 'status', 'started_at', 'completed_at', 'completed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => YearEndChecklistStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<YearEndChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(YearEndChecklistItem::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
