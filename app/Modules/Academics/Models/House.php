<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use Database\Factories\HouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $colour
 * @property int|null $patron_staff_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class House extends Model
{
    /** @use HasFactory<HouseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'colour',
        'patron_staff_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'patron_staff_id' => 'integer',
        ];
    }

    protected static function newFactory(): HouseFactory
    {
        return HouseFactory::new();
    }
}
