<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Database\Factories\DocumentSeriesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * docs/specs/10-documents.md 4.3 - the FORMAT and SCOPE of a document
 * number. The counter itself lives in `sequences` and is advanced only by
 * SequenceAllocator inside the render transaction; this row carries no
 * next_value on purpose (see the 310002 migration header).
 *
 * All document series are gaps-permitted - atomicity only, unlike
 * JournalEntry.piece_no.
 *
 * @property int $id
 * @property string $code
 * @property string $format
 * @property string $scope
 * @property string $reset_policy
 * @property int $padding
 * @property bool $is_active
 */
final class DocumentSeries extends Model
{
    /** @use HasFactory<DocumentSeriesFactory> */
    use HasFactory;

    protected $table = 'document_series';

    /**
     * 10-documents 19: the money documents whose DUPLICATA is reserved to
     * the money offices (`documents.reprint_financial`).
     */
    public const FINANCIAL_SERIES = ['RCPT', 'INV', 'CN', 'REF', 'PAY'];

    /** @var list<string> */
    protected $fillable = [
        'code', 'format', 'scope', 'reset_policy', 'padding', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DocumentSeries $series): void {
            // 4.3: "a format using {year} with scope = global fails
            // validation" - a global counter has no year to substitute, and
            // rendering the CURRENT year into it would silently produce a
            // serial that lies about its own scope.
            if ($series->scope === 'global'
                && (str_contains($series->format, '{year}')
                    || str_contains($series->format, '{date}')
                    || str_contains($series->format, '{month}'))) {
                throw new RuntimeException(sprintf(
                    'Series [%s] declares scope=global but its format [%s] uses a time token; '
                    .'a global counter has no year/date/month (10-documents 4.3).',
                    $series->code,
                    $series->format,
                ));
            }
        });
    }

    protected static function newFactory(): DocumentSeriesFactory
    {
        return DocumentSeriesFactory::new();
    }

    public function isFinancial(): bool
    {
        return in_array($this->code, self::FINANCIAL_SERIES, true);
    }
}
