<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PostingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostingRule>
 */
class PostingRuleFactory extends Factory
{
    /** @var class-string<PostingRule> */
    protected $model = PostingRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'rule_'.fake()->unique()->lexify('??????'),
            'version' => 1,
            'event' => PostingEvent::FeePaymentReceived->value,
            'journal_id' => Journal::factory(),
            'label_expression' => 'Test rule entry',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'is_locked' => false,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ];
    }
}
