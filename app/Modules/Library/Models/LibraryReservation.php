<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use App\Modules\Library\Domain\LibraryReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §10.4 - title-level (a member reserves *a* copy).
 * At return the queue head goes `ready`, the copy is parked `reserved`,
 * and the member is notified through the Communication outbox.
 *
 * @property int $id
 * @property int $book_id
 * @property int $library_member_id
 * @property string $reserved_on
 * @property string|null $expires_on
 * @property LibraryReservationStatus $status
 * @property int|null $book_copy_id
 * @property \Illuminate\Support\Carbon|null $notified_at
 * @property int $position
 * @property int|null $active_key
 */
final class LibraryReservation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'book_id', 'library_member_id', 'reserved_on', 'expires_on',
        'status', 'book_copy_id', 'notified_at', 'position',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'book_id' => 'integer',
            'library_member_id' => 'integer',
            'status' => LibraryReservationStatus::class,
            'book_copy_id' => 'integer',
            'notified_at' => 'datetime',
            'position' => 'integer',
            'active_key' => 'integer',
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
     * @return BelongsTo<LibraryMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'library_member_id');
    }
}
