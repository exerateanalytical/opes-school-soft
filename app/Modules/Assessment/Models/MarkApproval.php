<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use App\Modules\Assessment\Domain\ApprovalDecision;
use Database\Factories\MarkApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The batch header of docs/specs/01-assessment.md §7.3 — one row per
 * (subject allocation, assessment period, class group) — giving submission and
 * validation an auditable identity.
 *
 * Status transitions are conditional UPDATEs with an affected-rows check
 * (00-core §10.4), issued by the Actions; nothing here reads then writes.
 *
 * @property int $id
 * @property int $subject_allocation_id
 * @property int $assessment_period_id
 * @property int $class_group_id
 * @property string $status
 * @property ApprovalDecision|null $last_decision
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $validated_by
 * @property Carbon|null $validated_at
 * @property int|null $returned_by
 * @property Carbon|null $returned_at
 * @property string|null $return_reason
 * @property int $mark_count
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MarkApproval extends Model
{
    /** @use HasFactory<MarkApprovalFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_RETURNED = 'returned';

    /** @var list<string> */
    protected $fillable = [
        'subject_allocation_id',
        'assessment_period_id',
        'class_group_id',
        'status',
        'last_decision',
        'submitted_by',
        'submitted_at',
        'validated_by',
        'validated_at',
        'returned_by',
        'returned_at',
        'return_reason',
        'mark_count',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_allocation_id' => 'int',
            'assessment_period_id' => 'int',
            'class_group_id' => 'int',
            'last_decision' => ApprovalDecision::class,
            'submitted_by' => 'int',
            'submitted_at' => 'datetime',
            'validated_by' => 'int',
            'validated_at' => 'datetime',
            'returned_by' => 'int',
            'returned_at' => 'datetime',
            'mark_count' => 'int',
            'version' => 'int',
        ];
    }

    protected static function newFactory(): MarkApprovalFactory
    {
        return MarkApprovalFactory::new();
    }
}
