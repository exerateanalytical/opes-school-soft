<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use App\Modules\Library\Domain\BookCondition;
use App\Modules\Library\Domain\BookCopyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §10.2 - the physical thing; the unit of circulation.
 *
 * @property int $id
 * @property int $book_id
 * @property string $accession_no
 * @property string $barcode
 * @property int $shelf_location_id
 * @property int|null $acquisition_id
 * @property string|null $acquired_on
 * @property int $acquisition_cost
 * @property BookCondition $condition
 * @property BookCopyStatus $status
 * @property string|null $withdrawn_on
 * @property string|null $withdrawal_reason
 */
final class BookCopy extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'book_id', 'accession_no', 'barcode', 'shelf_location_id',
        'acquisition_id', 'acquired_on', 'acquisition_cost', 'condition',
        'status', 'withdrawn_on', 'withdrawal_reason',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'book_id' => 'integer',
            'shelf_location_id' => 'integer',
            'acquisition_id' => 'integer',
            'acquisition_cost' => 'integer',
            'condition' => BookCondition::class,
            'status' => BookCopyStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @return BelongsTo<ShelfLocation, $this>
     */
    public function shelfLocation(): BelongsTo
    {
        return $this->belongsTo(ShelfLocation::class);
    }

    /**
     * @return BelongsTo<BookAcquisition, $this>
     */
    public function acquisition(): BelongsTo
    {
        return $this->belongsTo(BookAcquisition::class, 'acquisition_id');
    }
}
