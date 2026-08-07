<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\Gender;
use App\Modules\Students\Domain\StudentStatus;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/07-students.md 3.
 *
 * @property int $id
 * @property string $matricule
 * @property bool $matricule_is_official
 * @property string $admission_no
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string|null $preferred_name
 * @property Carbon $date_of_birth
 * @property string|null $birth_certificate_no
 * @property string|null $place_of_birth
 * @property Gender $gender
 * @property string $nationality
 * @property string|null $state_of_origin
 * @property string|null $religion
 * @property string|null $blood_group
 * @property string|null $genotype
 * @property string|null $national_id_number
 * @property string|null $national_id_blind_index
 * @property string|null $photo_path
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address_line
 * @property string|null $city
 * @property string|null $region
 * @property int|null $house_id
 * @property StudentStatus $status
 * @property Carbon|null $first_admission_date
 * @property Carbon|null $left_on
 * @property Carbon|null $deceased_on
 * @property bool $is_archived
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $pseudonymised_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    /**
     * Biographical fields only.
     *
     * `matricule` and `matricule_is_official` are absent because 6.4 permits
     * exactly one supervised change and it goes through
     * PromoteMatriculeToOfficial, not through a fill().
     *
     * `status` is absent because 3.2 makes it a derived cache of the
     * enrollment history; applyDerivedStatus() is its only writer.
     *
     * `admission_no` is absent because it is issued once, by the sequence.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'preferred_name',
        'date_of_birth',
        'birth_certificate_no',
        'place_of_birth',
        'gender',
        'nationality',
        'state_of_origin',
        'religion',
        'blood_group',
        'genotype',
        'national_id_number',
        'photo_path',
        'phone',
        'email',
        'address_line',
        'city',
        'region',
        'house_id',
        'first_admission_date',
        'created_by',
        'updated_by',
    ];

    /**
     * Set for the duration of applyDerivedStatus() only. The guard below reads
     * it, so a plain `$student->status = ...; $student->save();` anywhere in
     * the codebase throws instead of quietly desynchronising the derived cache
     * from the enrollment history - which is defect the 3.2 rule exists to fix.
     */
    private bool $statusWriteAllowed = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'matricule_is_official' => 'boolean',
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'status' => StudentStatus::class,
            'first_admission_date' => 'date',
            'left_on' => 'date',
            'deceased_on' => 'date',
            'is_archived' => 'boolean',
            'house_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'pseudonymised_at' => 'datetime',

            // 00-core 9.5. Non-deterministic, so none of these can be indexed
            // or made unique; national_id_number carries a separate blind
            // index for that.
            'religion' => 'encrypted',
            'blood_group' => 'encrypted',
            'genotype' => 'encrypted',
            'national_id_number' => 'encrypted',
        ];
    }

    /**
     * The immutability observer (07-students 6.4).
     *
     * Registered here rather than in a ServiceProvider so that it cannot be
     * forgotten when the model is used from a console command, a queued job or
     * a test that never boots the HTTP kernel. The UNIQUE index guarantees no
     * two students share a matricule; this guarantees no ONE student's
     * matricule changes after it is official - a different property, and the
     * one that matters for a number printed on certificates.
     */
    protected static function booted(): void
    {
        static::updating(function (Student $student): void {
            // getOriginal(), not the current attribute: the check must ask
            // "was it official BEFORE this save", otherwise setting
            // matricule_is_official and matricule in the same call would slip
            // through.
            $wasOfficial = (bool) $student->getOriginal('matricule_is_official');

            if ($wasOfficial && $student->isDirty('matricule')) {
                throw new RuntimeException(sprintf(
                    'Student %s has an official matricule; it is immutable (07-students 6.4).',
                    (string) $student->getOriginal('matricule'),
                ));
            }

            if ($wasOfficial && $student->isDirty('matricule_is_official')) {
                throw new RuntimeException(
                    'An official matricule cannot be demoted back to temporary (07-students 6.4).'
                );
            }

            if ($student->isDirty('status') && ! $student->statusWriteAllowed) {
                throw new RuntimeException(
                    'Student.status is derived from enrollment history (07-students 3.2); '
                    .'write it through Student::applyDerivedStatus() from the enrollment Actions.'
                );
            }
        });
    }

    /**
     * The single guarded writer of the derived status.
     *
     * Called by the enrollment workstream's Actions (RecomputeStudentStatus and
     * every Action that writes an Enrollment status). Deliberately NOT a
     * transition method: 3.2 makes status a recomputed cache, so a
     * from -> to transition table here would fight the derivation instead of
     * describing it. The audit trail of the change is
     * StudentStatusTransition (3.3), written by the caller in the same
     * transaction.
     */
    public function applyDerivedStatus(StudentStatus $status): bool
    {
        $this->statusWriteAllowed = true;

        try {
            $this->status = $status;

            return $this->save();
        } finally {
            $this->statusWriteAllowed = false;
        }
    }

    /** 3.5: age is derived against business_date(), never stored. */
    public function ageInYears(?Carbon $on = null): int
    {
        return (int) $this->date_of_birth->diffInYears($on ?? Carbon::now());
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }

    // There is deliberately NO house() relation. House is an Academics model
    // and 00-core 6.2 forbids one module from touching another's Models -
    // tests/Architecture/ModuleBoundaryTest.php fails the build on it. The
    // RESTRICT foreign key on house_id still holds at the database level, and
    // a screen that needs the house name asks Academics for it.

    /**
     * @return HasMany<StudentStatusTransition, $this>
     */
    public function statusTransitions(): HasMany
    {
        return $this->hasMany(StudentStatusTransition::class);
    }

    /**
     * @return HasMany<StudentActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(StudentActivityLog::class);
    }

    /**
     * @return HasMany<StudentDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    /**
     * @return HasMany<StudentMedicalRecord, $this>
     */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(StudentMedicalRecord::class);
    }

    /**
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public function scopeWithStatus(Builder $query, StudentStatus $status): Builder
    {
        return $query->where('status', '=', $status->value);
    }

    /**
     * The default list scope for every student-facing screen: archived rows
     * stay in the database (00-core 10.5) but are not part of the roll.
     *
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', '=', StudentStatus::Active->value)
            ->where('is_archived', '=', false);
    }

    /**
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('is_archived', '=', false);
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): StudentFactory
    {
        return StudentFactory::new();
    }
}
