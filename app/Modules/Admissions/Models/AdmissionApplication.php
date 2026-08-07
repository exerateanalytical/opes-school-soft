<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Models;

use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Domain\WizardStep;
use Database\Factories\AdmissionApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A pre-student record, docs/specs/07-students.md 6.1.
 *
 * @property int $id
 * @property string|null $application_no
 * @property string|null $idempotency_key
 * @property int|null $academic_year_id
 * @property int|null $admission_term_id
 * @property int|null $school_section_id
 * @property int|null $class_level_id
 * @property int|null $stream_id
 * @property string|null $category
 * @property Carbon|null $admission_date
 * @property int|null $proposed_roll_number
 * @property string|null $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property Carbon|null $date_of_birth
 * @property string|null $gender
 * @property string|null $nationality
 * @property string|null $place_of_birth
 * @property string|null $state_of_origin
 * @property string|null $religion
 * @property string|null $blood_group
 * @property string|null $genotype
 * @property string|null $previous_school_name
 * @property string|null $last_class_completed
 * @property int|null $year_completed
 * @property string|null $reason_for_leaving
 * @property string|null $special_information
 * @property string|null $photo_path
 * @property ApplicationStatus $status
 * @property int $current_step
 * @property int $completed_step
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property int|null $converted_student_id
 * @property Carbon|null $purge_due_on
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AdmissionApplicationGuardian> $guardians
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AdmissionApplicationDocument> $documents
 */
final class AdmissionApplication extends Model
{
    /** @use HasFactory<AdmissionApplicationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'application_no',
        'idempotency_key',
        'academic_year_id',
        'admission_term_id',
        'school_section_id',
        'class_level_id',
        'stream_id',
        'category',
        'admission_date',
        'proposed_roll_number',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'gender',
        'nationality',
        'place_of_birth',
        'state_of_origin',
        'religion',
        'blood_group',
        'genotype',
        'previous_school_name',
        'last_class_completed',
        'year_completed',
        'reason_for_leaving',
        'special_information',
        'photo_path',
        'status',
        'current_step',
        'completed_step',
        'submitted_at',
        'decided_by',
        'decided_at',
        'decision_reason',
        'converted_student_id',
        'purge_due_on',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_year_id' => 'integer',
            'admission_term_id' => 'integer',
            'school_section_id' => 'integer',
            'class_level_id' => 'integer',
            'stream_id' => 'integer',
            'proposed_roll_number' => 'integer',
            'year_completed' => 'integer',
            'admission_date' => 'date',
            'date_of_birth' => 'date',
            'purge_due_on' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'status' => ApplicationStatus::class,
            'current_step' => 'integer',
            'completed_step' => 'integer',
            'decided_by' => 'integer',
            'converted_student_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',

            // 00-core 9.5 / 6.1: the identity fields a school has no business
            // exposing in a table dump. `encrypted` is application-level, so a
            // stolen .sql file yields ciphertext, and the columns are TEXT
            // because the envelope is far longer than the plaintext.
            'religion' => 'encrypted',
            'blood_group' => 'encrypted',
            'genotype' => 'encrypted',
            'special_information' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<AdmissionApplicationGuardian, $this>
     */
    public function guardians(): HasMany
    {
        return $this->hasMany(AdmissionApplicationGuardian::class)->orderBy('position');
    }

    /**
     * @return HasMany<AdmissionApplicationDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(AdmissionApplicationDocument::class);
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ], static fn (?string $part): bool => $part !== null && $part !== '')));
    }

    /** Whether the operator has already passed validation on `$step`. */
    public function hasCompleted(WizardStep $step): bool
    {
        return $this->completed_step >= $step->value;
    }

    public function isConverted(): bool
    {
        return $this->converted_student_id !== null;
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): AdmissionApplicationFactory
    {
        return AdmissionApplicationFactory::new();
    }
}
