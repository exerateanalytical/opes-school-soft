<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Homework/an assignment a teacher sets for a class group and subject.
 *
 * @property int $id
 * @property int $class_group_id
 * @property int $subject_id
 * @property string $title
 * @property \Illuminate\Support\Carbon $due_on
 */
final class Assignment extends Model
{
    protected $table = 'assignments';

    /** @var list<string> */
    protected $fillable = [
        'class_group_id', 'subject_id', 'set_by_user_id',
        'title', 'instructions', 'assigned_on', 'due_on',
        'max_score', 'is_published',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_on' => 'date',
            'due_on' => 'date',
            'max_score' => 'decimal:2',
            'is_published' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AssignmentSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class, 'assignment_id');
    }
}
