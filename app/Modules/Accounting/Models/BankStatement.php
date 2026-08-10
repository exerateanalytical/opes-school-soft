<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\StatementSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §13.1. Persistence only - every invariant that
 * matters is a CHECK in 390001 or a guard in ImportBankStatement.
 *
 * @property int $id
 * @property int $treasury_account_id
 * @property string $statement_reference
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $opening_balance
 * @property int $closing_balance
 * @property StatementSource $source
 * @property string|null $file_sha256
 * @property int $imported_by
 * @property Carbon $imported_at
 * @property string|null $notes
 */
final class BankStatement extends Model
{
    protected $table = 'bank_statements';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'opening_balance' => 'integer',
            'closing_balance' => 'integer',
            'imported_at' => 'datetime',
            'source' => StatementSource::class,
        ];
    }

    /**
     * @return HasMany<BankStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'bank_statement_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'treasury_account_id');
    }

    /**
     * The movement the document itself claims, independent of any line:
     * closing − opening. ImportBankStatement proves the lines sum to it.
     */
    public function declaredMovement(): int
    {
        return $this->closing_balance - $this->opening_balance;
    }
}
