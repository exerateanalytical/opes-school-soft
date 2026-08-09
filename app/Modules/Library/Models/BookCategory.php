<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use Database\Factories\BookCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 06-assets-stores.md §10.1 - managed taxonomy. Right-rail counts are
 * COPIES per category, derived by query, never stored here.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property int|null $parent_id
 * @property bool $is_archived
 */
final class BookCategory extends Model
{
    /** @use HasFactory<BookCategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'name_fr', 'parent_id', 'is_archived'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'is_archived' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BookCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Book, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
