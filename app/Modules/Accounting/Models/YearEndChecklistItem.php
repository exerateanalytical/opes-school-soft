<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\YearEndItemStatus;
use App\Modules\Accounting\Domain\YearEndStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §17.3 - one row per §17.2 step, per exercice.
 *
 * @property int $id
 * @property int $year_end_checklist_id
 * @property int $sequence
 * @property string $code
 * @property string $title
 * @property string $title_fr
 * @property bool $is_mandatory
 * @property bool $is_automated
 * @property YearEndItemStatus $status
 * @property int|null $completed_by
 * @property Carbon|null $completed_at
 * @property int|null $performed_by
 * @property string|null $waiver_reason
 * @property int|null $waived_by
 * @property Carbon|null $waived_at
 * @property string|null $evidence_type
 * @property int|null $evidence_id
 * @property array<string, mixed>|null $validation_result
 */
final class YearEndChecklistItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'year_end_checklist_id', 'sequence', 'code', 'title', 'title_fr',
        'is_mandatory', 'is_automated', 'status', 'completed_by', 'completed_at',
        'performed_by', 'waiver_reason', 'waived_by', 'waived_at',
        'evidence_type', 'evidence_id', 'validation_result',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_mandatory' => 'boolean',
            'is_automated' => 'boolean',
            'status' => YearEndItemStatus::class,
            'completed_at' => 'datetime',
            'waived_at' => 'datetime',
            'validation_result' => 'array',
        ];
    }

    /**
     * @return BelongsTo<YearEndChecklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(YearEndChecklist::class, 'year_end_checklist_id');
    }

    /** The §17.2 step this row records, when the code is one of them. */
    public function step(): ?YearEndStep
    {
        return YearEndStep::tryFrom($this->code);
    }
}
