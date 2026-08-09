<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\SanctionType;
use Database\Factories\DisciplineSanctionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The action taken on a case (migration 2026_08_09_260010).
 *
 * A `suspension` row is only ever created by ApplySanction, which flips the
 * enrollment through `Students\Actions\SuspendEnrollment` in the same
 * transaction — this model never touches `enrollments`.
 *
 * @property int $id
 * @property int $discipline_case_id
 * @property SanctionType $type
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property int $applied_by
 * @property Carbon|null $acknowledged_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DisciplineCase $case
 */
final class DisciplineSanction extends Model
{
    /** @use HasFactory<DisciplineSanctionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'discipline_case_id', 'type', 'starts_on', 'ends_on',
        'applied_by', 'acknowledged_at', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SanctionType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DisciplineCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplineCase::class, 'discipline_case_id');
    }

    protected static function newFactory(): DisciplineSanctionFactory
    {
        return DisciplineSanctionFactory::new();
    }
}
