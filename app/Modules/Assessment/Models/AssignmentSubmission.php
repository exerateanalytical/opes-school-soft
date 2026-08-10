<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $assignment_id
 * @property int $enrollment_id
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property string|null $score
 */
final class AssignmentSubmission extends Model
{
    protected $table = 'assignment_submissions';

    /** @var list<string> */
    protected $fillable = [
        'assignment_id', 'enrollment_id', 'submission_note', 'submitted_at', 'is_late',
        'score', 'feedback', 'graded_by_user_id', 'graded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
            'score' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }
}
