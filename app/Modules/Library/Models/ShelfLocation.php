<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use Database\Factories\ShelfLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 06-assets-stores.md §10.2 - `Shelf A1` and friends.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $section
 * @property int|null $capacity
 */
final class ShelfLocation extends Model
{
    /** @use HasFactory<ShelfLocationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'section', 'capacity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    protected static function newFactory(): ShelfLocationFactory
    {
        return ShelfLocationFactory::new();
    }
}
