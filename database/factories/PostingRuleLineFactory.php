<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Models\PostingRule;
use App\Modules\Accounting\Models\PostingRuleLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostingRuleLine>
 */
class PostingRuleLineFactory extends Factory
{
    /** @var class-string<PostingRuleLine> */
    protected $model = PostingRuleLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'posting_rule_id' => PostingRule::factory(),
            'sequence' => 1,
            'account_source' => AccountSource::Literal,
            'account_code' => '552',
            'account_path' => null,
            'sign' => LineSign::Debit,
            'amount_expression' => 'payment.amount',
            'is_balancing' => false,
            'partner_source' => null,
            'analytic_source' => null,
            'tax_code_source' => null,
            'due_date_source' => null,
            'iterates_over' => null,
            'label_expression' => 'Test line',
            'skip_if_zero' => true,
        ];
    }
}
