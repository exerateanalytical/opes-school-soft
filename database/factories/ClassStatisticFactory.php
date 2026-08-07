<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Assessment\Models\ClassStatistic;
use App\Modules\Assessment\Models\PeriodResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassStatistic>
 */
class ClassStatisticFactory extends Factory
{
    /** @var class-string<ClassStatistic> */
    protected $model = ClassStatistic::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_period_id' => fn (): int => (int) AssessmentPeriod::factory()->create()->getKey(),
            'class_group_id' => fn (): int => $this->classGroupId(),
            'subject_allocation_id' => ClassStatistic::GENERAL,
            'cohort_key' => PeriodResult::COHORT_KEY_ALL,
            'n' => 6,
            'mean' => '13.377',
            'min_score' => '11.400',
            'max_score' => '15.200',
            'median' => '13.010',
            'stdev_population' => '1.2345',
            'pass_count' => 6,
            'pass_rate' => '100.00',
            'computed_at' => now(),
        ];
    }

    /**
     * 10.2: a cohort in which nobody has a non-NULL average has NO statistics.
     * Not a mean of zero and not a 0 % pass rate - v1's zeroes were
     * indistinguishable from a genuinely failing class.
     */
    public function empty(): self
    {
        return $this->state(fn (): array => [
            'n' => 0,
            'mean' => null,
            'min_score' => null,
            'max_score' => null,
            'median' => null,
            'stdev_population' => null,
            'pass_count' => 0,
            'pass_rate' => null,
        ]);
    }

    /**
     * Reuses PeriodResultFactory's reference-row bootstrap rather than
     * duplicating it: both need one class group and neither may reach for the
     * Academics factories, which belong to another workstream.
     */
    private function classGroupId(): int
    {
        return PeriodResultFactory::referenceClassGroupId();
    }
}
