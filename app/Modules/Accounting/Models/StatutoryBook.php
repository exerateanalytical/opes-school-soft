<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\StatutoryBookType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated statutory book (02-accounting §14.1).
 *
 * Immutable by convention: nothing in the product UPDATEs a row here. A
 * regeneration inserts a new row whose `supersedes_book_id` points at the
 * previous one, which is what makes the version chain auditable.
 *
 * @property int $id
 * @property StatutoryBookType $book_type
 * @property int $fiscal_year_id
 * @property string $sha256
 * @property int|null $supersedes_book_id
 * @property bool $is_definitive
 * @property int $total_debit
 * @property int $total_credit
 * @property int $line_count
 */
final class StatutoryBook extends Model
{
    protected $table = 'statutory_books';

    /** @var list<string> */
    protected $fillable = [
        'book_type', 'fiscal_year_id', 'period_start', 'period_end',
        'generated_at', 'generated_by', 'page_count',
        'first_piece_no', 'last_piece_no',
        'total_debit', 'total_credit', 'entry_count', 'line_count',
        'file_path', 'sha256', 'signature',
        'supersedes_book_id', 'is_definitive',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'book_type' => StatutoryBookType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
            'total_debit' => 'integer',
            'total_credit' => 'integer',
            'entry_count' => 'integer',
            'line_count' => 'integer',
            'page_count' => 'integer',
            'is_definitive' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_book_id');
    }
}
