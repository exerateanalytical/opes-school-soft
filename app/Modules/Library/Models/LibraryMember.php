<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use App\Modules\Library\Domain\MemberStatus;
use App\Modules\Library\Domain\MemberType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 06-assets-stores.md §10.3. Student memberships key on the ENROLLMENT
 * (year scope); the fine receivable keys on the STUDENT (a debt survives
 * the year). Both columns populated; invariant `student_id =
 * enrollment.student_id` is enforced by EnrollLibraryMember, which
 * DERIVES the student from the enrollment rather than accepting it.
 *
 * @property int $id
 * @property string $member_no
 * @property MemberType $member_type
 * @property int|null $student_id
 * @property int|null $staff_member_id
 * @property int|null $enrollment_id
 * @property int $academic_year_id
 * @property int $membership_class_id
 * @property MemberStatus $status
 * @property string $joined_on
 * @property string|null $expires_on
 * @property string|null $suspended_reason
 * @property string|null $external_name
 * @property string|null $external_contact
 * @property \Illuminate\Support\Carbon|null $card_issued_at
 * @property int $card_printed_count
 * @property int $created_by
 * @property string|null $idempotency_key
 */
final class LibraryMember extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'member_no', 'member_type', 'student_id', 'staff_member_id',
        'enrollment_id', 'academic_year_id', 'membership_class_id', 'status',
        'joined_on', 'expires_on', 'suspended_reason', 'external_name',
        'external_contact', 'card_issued_at', 'card_printed_count',
        'created_by', 'idempotency_key',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'member_type' => MemberType::class,
            'student_id' => 'integer',
            'staff_member_id' => 'integer',
            'enrollment_id' => 'integer',
            'academic_year_id' => 'integer',
            'membership_class_id' => 'integer',
            'status' => MemberStatus::class,
            'card_issued_at' => 'datetime',
            'card_printed_count' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MembershipClass, $this>
     */
    public function membershipClass(): BelongsTo
    {
        return $this->belongsTo(MembershipClass::class);
    }

    /**
     * @return HasMany<LibraryIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(LibraryIssue::class);
    }

    /**
     * @return HasMany<LibraryFine, $this>
     */
    public function fines(): HasMany
    {
        return $this->hasMany(LibraryFine::class);
    }
}
