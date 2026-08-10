<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\ImportBatchStatus;
use App\Modules\Students\Domain\ImportKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded file (00-core §15 Phase 2).
 *
 * @property int $id
 * @property ImportKind $kind
 * @property ImportBatchStatus $status
 * @property int $row_count
 * @property int $valid_count
 * @property int $invalid_count
 * @property int $imported_count
 */
final class ImportBatch extends Model
{
    protected $table = 'import_batches';

    /** @var list<string> */
    protected $fillable = [
        'kind', 'original_filename', 'sha256', 'status',
        'row_count', 'valid_count', 'invalid_count', 'imported_count',
        'uploaded_by', 'uploaded_at', 'committed_by', 'committed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ImportKind::class,
            'status' => ImportBatchStatus::class,
            'uploaded_at' => 'datetime',
            'committed_at' => 'datetime',
            'row_count' => 'integer',
            'valid_count' => 'integer',
            'invalid_count' => 'integer',
            'imported_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_batch_id');
    }
}
