<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\SanctionType;
use Database\Factories\DisciplineCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The offence catalogue (migration 2026_08_09_260008). Welfare-owned; other
 * modules read discipline data ONLY through
 * `Welfare\Actions\GetDisciplineCountsForEnrollments` (ModuleBoundaryTest).
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_fr
 * @property int $severity
 * @property SanctionType|null $default_sanction_type
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DisciplineCategory extends Model
{
    /** @use HasFactory<DisciplineCategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name', 'name_fr', 'severity', 'default_sanction_type', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => 'integer',
            'default_sanction_type' => SanctionType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DisciplineCase, $this>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(DisciplineCase::class, 'discipline_category_id');
    }

    protected static function newFactory(): DisciplineCategoryFactory
    {
        return DisciplineCategoryFactory::new();
    }
}
