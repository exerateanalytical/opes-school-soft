<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Support\Retention\Immutable10Year;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The état de rapprochement (02-accounting §13.3/§14), registered as a
 * hashed document the moment its `ReconciliationSession` closes.
 *
 * Immutable by convention, same as `StatutoryBook`: nothing UPDATEs a row
 * here, and a regeneration inserts a new row whose `supersedes_id` points at
 * the previous one.
 *
 * @property int $id
 * @property int $reconciliation_session_id
 * @property string $file_path
 * @property string $sha256
 * @property int|null $supersedes_id
 */
final class ReconciliationStatement extends Model
{
    use Immutable10Year;

    protected $table = 'reconciliation_statements';

    /** @var list<string> */
    protected $fillable = [
        'reconciliation_session_id',
        'generated_at',
        'generated_by',
        'file_path',
        'sha256',
        'supersedes_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReconciliationSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ReconciliationSession::class, 'reconciliation_session_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
