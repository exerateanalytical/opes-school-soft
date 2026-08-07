<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use App\Modules\Academics\Domain\AcademicYearStatus;
use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 00-core 8. Persistence only - the gapless-contiguity and single-current
 * invariants are enforced by the Actions (under lock) and by the schema (the
 * current_key generated unique), never by model events.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property bool $is_current
 * @property AcademicYearStatus $status
 * @property int|null $current_key
 */
final class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'starts_on', 'ends_on', 'is_current', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'status' => AcademicYearStatus::class,
        ];
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): AcademicYearFactory
    {
        return AcademicYearFactory::new();
    }

    /**
     * @return HasMany<AssessmentPeriod, $this>
     */
    public function assessmentPeriods(): HasMany
    {
        return $this->hasMany(AssessmentPeriod::class);
    }
}
