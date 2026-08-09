<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\BoardingScope;
use App\Modules\Fees\Domain\EnrollmentStatusScope;
use App\Modules\Fees\Domain\FeeStructureStatus;
use Database\Factories\FeeStructureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 04-fees.md §2.5. `class_level_id` / `stream_id` use the NOT NULL sentinel
 * 0 for "any" - MySQL's duplicate-NULL UNIQUE behaviour would otherwise
 * defeat the scope uniqueness entirely (see the migration docblock).
 *
 * Resolution ("most specific match wins", tie = configuration error) lives
 * in the Actions, never here.
 *
 * @property int $id
 * @property int $academic_year_id
 * @property int $school_section_id
 * @property int $class_level_id 0 = any level (sentinel)
 * @property int $stream_id 0 = any stream (sentinel)
 * @property EnrollmentStatusScope $enrollment_status_scope
 * @property BoardingScope $boarding_scope
 * @property string $name
 * @property FeeStructureStatus $status
 * @property int $version
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to exclusive
 */
final class FeeStructure extends Model
{
    /** @use HasFactory<FeeStructureFactory> */
    use HasFactory;

    public const ANY = 0;

    /** @var list<string> */
    protected $fillable = [
        'academic_year_id', 'school_section_id', 'class_level_id', 'stream_id',
        'enrollment_status_scope', 'boarding_scope', 'name', 'status',
        'version', 'effective_from', 'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_year_id' => 'integer',
            'school_section_id' => 'integer',
            'class_level_id' => 'integer',
            'stream_id' => 'integer',
            'enrollment_status_scope' => EnrollmentStatusScope::class,
            'boarding_scope' => BoardingScope::class,
            'status' => FeeStructureStatus::class,
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function newFactory(): FeeStructureFactory
    {
        return FeeStructureFactory::new();
    }

    /**
     * @return HasMany<FeeStructureLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(FeeStructureLine::class)->orderBy('display_order');
    }

    /**
     * §2.5 resolution rule: specificity is scored by counting non-sentinel,
     * non-`any` discriminators - class_level(8) + stream(4) + boarding(2) +
     * enrollment_status(1). Highest score among matching active structures
     * wins; a tie is a configuration error.
     */
    public function specificityScore(): int
    {
        return ($this->class_level_id !== self::ANY ? 8 : 0)
            + ($this->stream_id !== self::ANY ? 4 : 0)
            + ($this->boarding_scope !== BoardingScope::Any ? 2 : 0)
            + ($this->enrollment_status_scope !== EnrollmentStatusScope::Any ? 1 : 0);
    }
}
