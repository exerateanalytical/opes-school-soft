<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\LetteringStatus;
use App\Modules\Accounting\Domain\PartnerType;
use Database\Factories\LetteringFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §10.2. Persistence only - LT-1 through LT-6
 * are enforced by LetterEntries / UnletterGroup under
 * `SELECT ... FOR UPDATE` on this row (§10.3), not by model events, exactly
 * like JournalEntry's convention (see that model's docblock).
 *
 * @property int $id
 * @property int $account_id
 * @property PartnerType $partner_type
 * @property int $partner_id
 * @property string $code
 * @property LetteringStatus $status
 * @property int $total_debit
 * @property int $total_credit
 * @property int $lettered_by
 * @property Carbon $lettered_at
 * @property int|null $unlettered_by
 * @property Carbon|null $unlettered_at
 * @property string|null $unletter_reason
 * @property bool $is_auto
 */
final class Lettering extends Model
{
    /** @use HasFactory<LetteringFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'account_id',
        'partner_type',
        'partner_id',
        'code',
        'status',
        'total_debit',
        'total_credit',
        'lettered_by',
        'lettered_at',
        'unlettered_by',
        'unlettered_at',
        'unletter_reason',
        'is_auto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'partner_type' => PartnerType::class,
            'status' => LetteringStatus::class,
            'total_debit' => 'integer',
            'total_credit' => 'integer',
            'lettered_at' => 'datetime',
            'unlettered_at' => 'datetime',
            'is_auto' => 'boolean',
        ];
    }

    protected static function newFactory(): LetteringFactory
    {
        return LetteringFactory::new();
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * LT-2/§10.3's stated primary mechanism: the row lock LetterEntries and
     * UnletterGroup take before recomputing totals, so two concurrent
     * allocations against the same (account, partner) group serialise
     * instead of both reading a stale total and both writing "full".
     */
    public static function lockForLettering(int $id): self
    {
        /** @var self $lettering */
        $lettering = self::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $lettering;
    }
}
