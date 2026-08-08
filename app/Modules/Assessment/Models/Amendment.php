<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/01-assessment.md 15.1 - `ReportCardAmendment`, C10.
 *
 * The table is `report_card_amendments`; the class is `Amendment` because it
 * lives in `App\Modules\Assessment\Models`, where the `ReportCard` prefix is
 * already carried by the namespace.
 *
 * Read the column list for what is NOT here: there is no `enrollment_id`. A
 * post-publication mark correction changes that student's average, which
 * changes the class mean, min, max, pass rate, standard deviation and every
 * other student's rank - all already printed on 61 other cards. The amendment
 * therefore belongs to the PUBLICATION and records the class-wide consequence
 * in `affected_enrollment_ids`.
 *
 * @property int $id
 * @property int $period_publication_id
 * @property int $from_generation
 * @property int $to_generation
 * @property string $reason
 * @property int|null $requested_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string $rank_freeze_policy
 * @property list<int>|null $affected_enrollment_ids
 * @property list<array<string, mixed>> $mark_changes
 * @property string $status
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Amendment extends Model
{
    // No factory, for the reason given on ReportCardSnapshot: an amendment is
    // the record of a supervised, approved change to an issued document.
    // Manufacturing one outside AmendMarks would manufacture the approval.

    protected $table = 'report_card_amendments';

    /**
     * 15.2. Ranks and statistics are recomputed and every affected card is
     * reissued. Correct, and expensive.
     */
    public const POLICY_REISSUE_CLASS = 'reissue_class';

    /**
     * 15.2. The corrected student's own numbers are updated; ranks and class
     * statistics stay at their generation-1 values and the card prints
     * "Classement fige au JJ/MM/AAAA". This exists because a school will not
     * recall 62 cards for a 0.25-point correction, and pretending otherwise
     * produces off-ledger manual edits.
     */
    public const POLICY_FREEZE_AT_PUBLICATION = 'freeze_at_publication';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPLIED = 'applied';

    /** @var list<string> */
    protected $fillable = [
        'period_publication_id',
        'from_generation',
        'to_generation',
        'reason',
        'requested_by',
        'approved_by',
        'approved_at',
        'rank_freeze_policy',
        'affected_enrollment_ids',
        'mark_changes',
        'status',
        'applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_publication_id' => 'integer',
            'from_generation' => 'integer',
            'to_generation' => 'integer',
            'affected_enrollment_ids' => 'array',
            'mark_changes' => 'array',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PeriodPublication, $this>
     */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(PeriodPublication::class, 'period_publication_id');
    }

    public function freezesRanks(): bool
    {
        return $this->rank_freeze_policy === self::POLICY_FREEZE_AT_PUBLICATION;
    }
}
