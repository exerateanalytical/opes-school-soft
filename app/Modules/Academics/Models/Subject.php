<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A teachable subject (Mathematics, Anglais, ...). Owned by Academics
 * (01-assessment header); everything year- and class-specific lives on
 * SubjectAllocation, never here.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_fr
 * @property int|null $department_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'department_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'department_id' => 'int',
            'is_active' => 'bool',
        ];
    }

    protected static function newFactory(): SubjectFactory
    {
        return SubjectFactory::new();
    }

    /**
     * @return HasMany<SubjectAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SubjectAllocation::class);
    }
}
