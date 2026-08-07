<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $capacity
 * @property string|null $building
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'capacity',
        'building',
        'type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    protected static function newFactory(): RoomFactory
    {
        return RoomFactory::new();
    }
}
