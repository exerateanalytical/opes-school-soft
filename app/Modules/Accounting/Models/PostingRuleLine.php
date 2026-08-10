<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use Database\Factories\PostingRuleLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Retention\Immutable10Year;

/**
 * docs/specs/02-accounting.md §11.1.
 *
 * @property int $id
 * @property int $posting_rule_id
 * @property int $sequence
 * @property AccountSource $account_source
 * @property string|null $account_code
 * @property string|null $account_path
 * @property LineSign $sign
 * @property string $amount_expression
 * @property bool $is_balancing
 * @property string|null $partner_source
 * @property string|null $analytic_source
 * @property string|null $tax_code_source
 * @property string|null $due_date_source
 * @property string|null $iterates_over
 * @property string $label_expression
 * @property bool $skip_if_zero
 */
final class PostingRuleLine extends Model
{
    use Immutable10Year;
    /** @use HasFactory<PostingRuleLineFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'posting_rule_id', 'sequence', 'account_source', 'account_code',
        'account_path', 'sign', 'amount_expression', 'is_balancing',
        'partner_source', 'analytic_source', 'tax_code_source',
        'due_date_source', 'iterates_over', 'label_expression', 'skip_if_zero',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'account_source' => AccountSource::class,
            'sign' => LineSign::class,
            'is_balancing' => 'boolean',
            'skip_if_zero' => 'boolean',
        ];
    }

    protected static function newFactory(): PostingRuleLineFactory
    {
        return PostingRuleLineFactory::new();
    }

    /**
     * @return BelongsTo<PostingRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PostingRule::class, 'posting_rule_id');
    }
}
