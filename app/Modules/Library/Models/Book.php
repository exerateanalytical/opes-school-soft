<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use App\Modules\Library\Domain\BookCopyStatus;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 06-assets-stores.md §10.1 - the TITLE (bibliographic) record; the
 * circulating unit is BookCopy. `availableCopies()` is derived by COUNT,
 * never a stored counter - a stored counter is exactly how libraries end
 * up issuing a book that is not on the shelf.
 *
 * @property int $id
 * @property string|null $isbn
 * @property string $title
 * @property string|null $subtitle
 * @property string $author
 * @property string|null $co_authors
 * @property string|null $publisher
 * @property int|null $publication_year
 * @property string|null $edition
 * @property string $language
 * @property int $book_category_id
 * @property string|null $dewey_or_call_number
 * @property int|null $pages
 * @property string|null $summary
 * @property string|null $cover_path
 * @property int $replacement_cost
 * @property bool $is_reference_only
 * @property bool $is_archived
 * @property int|null $created_by
 */
final class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'isbn', 'title', 'subtitle', 'author', 'co_authors', 'publisher',
        'publication_year', 'edition', 'language', 'book_category_id',
        'dewey_or_call_number', 'pages', 'summary', 'cover_path',
        'replacement_cost', 'is_reference_only', 'is_archived', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'publication_year' => 'integer',
            'book_category_id' => 'integer',
            'pages' => 'integer',
            'replacement_cost' => 'integer',
            'is_reference_only' => 'boolean',
            'is_archived' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BookCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BookCategory::class, 'book_category_id');
    }

    /**
     * @return HasMany<BookCopy, $this>
     */
    public function copies(): HasMany
    {
        return $this->hasMany(BookCopy::class);
    }

    /** Derived, by design (§10.2). */
    public function availableCopies(): int
    {
        return $this->copies()->where('status', BookCopyStatus::Available->value)->count();
    }

    protected static function newFactory(): BookFactory
    {
        return BookFactory::new();
    }
}
