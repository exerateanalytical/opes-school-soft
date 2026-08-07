<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use App\Modules\Academics\Domain\EducationLevel;
use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Academics\Domain\Track;
use Database\Factories\SchoolSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One (education_level, track, sub_system) combination - the unit the rest of
 * the academic structure hangs from (docs/specs/00-core.md 8). The triple is
 * UNIQUE at the database level.
 *
 * @property int $id
 * @property EducationLevel $education_level
 * @property Track $track
 * @property SubSystem $sub_system
 * @property string $name
 * @property string $name_fr
 * @property string $matricule_format
 * @property int $display_order
 * @property bool $is_active
 */
final class SchoolSection extends Model
{
    /** @use HasFactory<SchoolSectionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'education_level', 'track', 'sub_system',
        'name', 'name_fr', 'matricule_format', 'display_order', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'education_level' => EducationLevel::class,
            'track' => Track::class,
            'sub_system' => SubSystem::class,
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): SchoolSectionFactory
    {
        return SchoolSectionFactory::new();
    }

    /**
     * @return HasMany<ClassLevel, $this>
     */
    public function classLevels(): HasMany
    {
        return $this->hasMany(ClassLevel::class);
    }

    /**
     * @return HasMany<Stream, $this>
     */
    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class);
    }
}
