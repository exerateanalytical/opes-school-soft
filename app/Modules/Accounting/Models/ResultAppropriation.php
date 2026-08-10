<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §18.3. Persistence only - AP-1
 * (Σ lines = result_amount) is asserted in RecordResultAppropriation and
 * again in PostResultAppropriation, under `FOR UPDATE`.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property string $decision_body
 * @property Carbon $decision_date
 * @property string $resolution_reference
 * @property int $result_amount signed
 * @property string $status draft|approved
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $journal_entry_id
 * @property string|null $document_path
 * @property string|null $document_sha256
 */
final class ResultAppropriation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    /** @var list<string> */
    protected $fillable = [
        'fiscal_year_id', 'decision_body', 'decision_date', 'resolution_reference',
        'result_amount', 'status', 'approved_by', 'approved_at', 'journal_entry_id',
        'document_path', 'document_sha256',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision_date' => 'date',
            'result_amount' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ResultAppropriationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ResultAppropriationLine::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
