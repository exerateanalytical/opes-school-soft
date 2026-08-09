<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use App\Modules\Library\Domain\IssueStatus;
use App\Modules\Library\Domain\ReturnCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 06-assets-stores.md §10.4 - the loan. `open_copy_key` is DB-generated
 * (copy id while open/overdue) with `uq_open_issue` on it: the last copy
 * cannot be issued twice, by the database, not by a check-then-act read.
 *
 * @property int $id
 * @property string $issue_no
 * @property int $book_copy_id
 * @property int $library_member_id
 * @property string $issued_on
 * @property string $due_on
 * @property int $issued_by
 * @property string|null $returned_on
 * @property int|null $received_by
 * @property int $renewal_count
 * @property IssueStatus $status
 * @property ReturnCondition|null $return_condition
 * @property int|null $open_copy_key
 * @property string|null $idempotency_key
 */
final class LibraryIssue extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'issue_no', 'book_copy_id', 'library_member_id', 'issued_on',
        'due_on', 'issued_by', 'returned_on', 'received_by',
        'renewal_count', 'status', 'return_condition', 'idempotency_key',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'book_copy_id' => 'integer',
            'library_member_id' => 'integer',
            'issued_by' => 'integer',
            'received_by' => 'integer',
            'renewal_count' => 'integer',
            'status' => IssueStatus::class,
            'return_condition' => ReturnCondition::class,
            'open_copy_key' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BookCopy, $this>
     */
    public function copy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }

    /**
     * @return BelongsTo<LibraryMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'library_member_id');
    }

    /**
     * @return HasMany<LibraryRenewal, $this>
     */
    public function renewals(): HasMany
    {
        return $this->hasMany(LibraryRenewal::class);
    }

    /**
     * @return HasMany<LibraryFine, $this>
     */
    public function fines(): HasMany
    {
        return $this->hasMany(LibraryFine::class);
    }
}
