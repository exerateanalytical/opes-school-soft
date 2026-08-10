<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\ImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One data line from an uploaded file.
 *
 * @property int $id
 * @property int $import_batch_id
 * @property int $row_no
 * @property array<string, mixed> $payload
 * @property ImportRowStatus $status
 * @property array<string, mixed>|null $errors
 */
final class ImportRow extends Model
{
    protected $table = 'import_rows';

    /** @var list<string> */
    protected $fillable = [
        'import_batch_id', 'row_no', 'payload', 'status', 'errors',
        'imported_record_type', 'imported_record_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'errors' => 'array',
            'status' => ImportRowStatus::class,
            'row_no' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
