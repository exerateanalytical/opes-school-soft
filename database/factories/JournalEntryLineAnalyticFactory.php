<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\AnalyticValue;
use App\Modules\Accounting\Models\JournalEntryLineAnalytic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare pivot row for tests that need to hand-insert a DELIBERATE violation
 * for VerifyAnalyticAllocations to catch. Real allocations must go through
 * AllocateLineAnalytics - that Action, not this factory, is what proves
 * AN-1/AN-2 by construction.
 *
 * `journal_entry_line_id` has no default on purpose: a line needs a whole
 * posted-entry scaffold, which the accounting test helpers already build.
 *
 * @extends Factory<JournalEntryLineAnalytic>
 */
class JournalEntryLineAnalyticFactory extends Factory
{
    /** @var class-string<JournalEntryLineAnalytic> */
    protected $model = JournalEntryLineAnalytic::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analytic_value_id' => AnalyticValue::factory(),
            'analytic_axis_id' => static fn (array $attributes): mixed => AnalyticValue::query()
                ->whereKey($attributes['analytic_value_id'])
                ->value('analytic_axis_id'),
            'amount' => 10_000,
            'share_bp' => 1_000_000,
        ];
    }
}
