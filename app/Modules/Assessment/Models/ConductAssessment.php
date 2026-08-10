<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The MINESEC conduct block for one student in one period (§12.3).
 *
 * Conduct is NOT an input to the general average and never enters §10.1.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $assessment_period_id
 */
final class ConductAssessment extends Model
{
    protected $table = 'conduct_assessments';

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id', 'assessment_period_id', 'conduct_scale_id',
        'conduite_level_id', 'travail_level_id', 'assiduite_level_id',
        'discipline_level_id', 'tenue_level_id',
        'assessed_by_staff_id', 'assessed_at', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['assessed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ConductScale, $this>
     */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(ConductScale::class, 'conduct_scale_id');
    }
}
