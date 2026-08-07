<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\DocumentVerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/07-students.md 8.1. Keyed on student_id per the 3.4 matrix.
 *
 * @property int $id
 * @property int $student_id
 * @property int|null $document_type_id
 * @property string $title
 * @property string $file_path
 * @property string $file_hash
 * @property string $mime
 * @property int $size_bytes
 * @property Carbon|null $issued_on
 * @property Carbon|null $expires_on
 * @property DocumentVerificationStatus $verification_status
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $notes
 * @property int|null $uploaded_by
 * @property bool $is_archived
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StudentDocument extends Model
{
    /**
     * verification_status is absent: moving a document out of `unverified` is
     * a staff decision that must record who made it and when, so it goes
     * through the verification Action rather than a fill().
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'document_type_id',
        'title',
        'file_path',
        'file_hash',
        'mime',
        'size_bytes',
        'issued_on',
        'expires_on',
        'notes',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'document_type_id' => 'integer',
            'size_bytes' => 'integer',
            'issued_on' => 'date',
            'expires_on' => 'date',
            'verification_status' => DocumentVerificationStatus::class,
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'uploaded_by' => 'integer',
            'is_archived' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @param  Builder<StudentDocument>  $query
     * @return Builder<StudentDocument>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('is_archived', '=', false);
    }
}
