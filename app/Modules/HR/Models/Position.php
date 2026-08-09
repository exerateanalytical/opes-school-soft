<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A job position (docs/specs/05-hr-payroll.md 3.4). `is_teaching` gates
 * class assignments: only a contract on a teaching position may be assigned
 * to a class group.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_fr
 * @property bool $is_teaching
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'is_teaching',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_teaching' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): PositionFactory
    {
        return PositionFactory::new();
    }
}
