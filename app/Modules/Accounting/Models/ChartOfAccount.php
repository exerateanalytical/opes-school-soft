<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\AccountType;
use App\Modules\Accounting\Domain\BudgetControl;
use App\Modules\Accounting\Domain\DsfStatement;
use App\Modules\Accounting\Domain\NormalBalance;
use Database\Factories\ChartOfAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use App\Support\Retention\Immutable10Year;

/**
 * SYSCOHADA revise chart of accounts (02-accounting.md 2.1).
 *
 * Persistence only. The hierarchy invariants (CoA-1, CoA-2, CoA-4, CoA-5) are
 * enforced by database triggers (2026_08_07_230001), not by model events -
 * see that migration for why a trigger, not an observer, is the primary
 * mechanism. `account_class` and `depth` are STORED generated columns and
 * therefore never appear in `$fillable`.
 *
 * @property int $id
 * @property string $code
 * @property int|null $parent_id
 * @property int $account_class
 * @property int $depth
 * @property string $name
 * @property string $name_fr
 * @property string|null $name_en
 * @property string|null $display_alias
 * @property AccountType $type
 * @property NormalBalance $normal_balance
 * @property bool $is_postable
 * @property bool $is_system
 * @property bool $is_collective
 * @property bool $requires_partner
 * @property list<string>|null $allowed_partner_types
 * @property bool $requires_analytic
 * @property bool $is_lettrable
 * @property bool $is_reconcilable
 * @property string|null $dsf_line_code
 * @property DsfStatement|null $dsf_statement
 * @property int|null $default_tax_code_id
 * @property BudgetControl $budget_control
 * @property string $currency
 * @property bool $is_archived
 * @property Carbon|null $opened_at
 * @property Carbon|null $archived_at
 * @property string|null $notes
 */
final class ChartOfAccount extends Model
{
    use Immutable10Year;
    /** @use HasFactory<ChartOfAccountFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'parent_id', 'name', 'name_fr', 'name_en', 'display_alias',
        'type', 'normal_balance', 'is_postable', 'is_system', 'is_collective',
        'requires_partner', 'allowed_partner_types', 'requires_analytic',
        'is_lettrable', 'is_reconcilable', 'dsf_line_code', 'dsf_statement',
        'default_tax_code_id', 'budget_control', 'currency', 'is_archived',
        'opened_at', 'archived_at', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_postable' => 'boolean',
            'is_system' => 'boolean',
            'is_collective' => 'boolean',
            'requires_partner' => 'boolean',
            'allowed_partner_types' => 'array',
            'requires_analytic' => 'boolean',
            'is_lettrable' => 'boolean',
            'is_reconcilable' => 'boolean',
            'dsf_statement' => DsfStatement::class,
            'budget_control' => BudgetControl::class,
            'is_archived' => 'boolean',
            'opened_at' => 'date',
            'archived_at' => 'date',
        ];
    }

    protected static function newFactory(): ChartOfAccountFactory
    {
        return ChartOfAccountFactory::new();
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ChartOfAccount, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
