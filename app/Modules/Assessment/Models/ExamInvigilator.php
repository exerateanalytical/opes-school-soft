<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One staff member on one paper — docs/specs/01-assessment.md 16.1.
 *
 * The temporal invariant (invariant 17) is NOT enforced here. A model cannot
 * hold a lock, and a saving-event check would be a read-then-write race that
 * two simultaneous assignments walk straight through. It lives in
 * `Assessment\Actions\AssignInvigilators`, under `FOR UPDATE`.
 *
 * There is no `staff()` relation. `StaffMember` belongs to the HR module and
 * tests/Architecture/ModuleBoundaryTest.php forbids naming another module's
 * Models from here — the staff member's name is read through the query
 * builder by whatever needs to print it.
 *
 * @property int $id
 * @property int $exam_id
 * @property int $staff_id
 * @property string $role
 * @property int $assigned_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ExamInvigilator extends Model
{
    public const string ROLE_CHIEF = 'chief';

    public const string ROLE_ASSISTANT = 'assistant';

    /** @var list<string> */
    public const array ROLES = [self::ROLE_CHIEF, self::ROLE_ASSISTANT];

    /** @var list<string> */
    protected $fillable = [
        'exam_id',
        'staff_id',
        'role',
        'assigned_by',
    ];

    /**
     * @return BelongsTo<Exam, $this>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
