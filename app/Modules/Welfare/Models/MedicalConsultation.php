<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\ConsultationOutcome;
use App\Modules\Welfare\Domain\ConsultationSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sick-bay visit (docs/plans/phase-10.md §3 row 8). The clinical
 * narrative - complaint, diagnosis, treatment - is health data about a
 * minor (00-core 9.5) and carries the `'encrypted'` cast exactly as
 * StudentMedicalRecord.detail does: the DB column holds ciphertext, only
 * model reads decrypt. student_id is a plain integer, NOT a relation:
 * Welfare never reaches into Students' Models (ModuleBoundaryTest);
 * identity fields are joined via DB::table inside Actions/screens.
 *
 * @property int $id
 * @property int $student_id
 * @property int|null $enrollment_id
 * @property Carbon $visited_at
 * @property string $presenting_complaint
 * @property string|null $diagnosis
 * @property string|null $treatment
 * @property ConsultationSeverity $severity
 * @property ConsultationOutcome $outcome
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MedicalConsultation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'student_id', 'enrollment_id', 'visited_at',
        'presenting_complaint', 'diagnosis', 'treatment',
        'severity', 'outcome', 'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'enrollment_id' => 'integer',
            'visited_at' => 'datetime',
            // 00-core 9.5: health data about a minor, never plaintext at rest.
            'presenting_complaint' => 'encrypted',
            'diagnosis' => 'encrypted',
            'treatment' => 'encrypted',
            'severity' => ConsultationSeverity::class,
            'outcome' => ConsultationOutcome::class,
            'recorded_by' => 'integer',
        ];
    }

    /**
     * @return HasMany<MedicalReferral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(MedicalReferral::class, 'consultation_id');
    }
}
