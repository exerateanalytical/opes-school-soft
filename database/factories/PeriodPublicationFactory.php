<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assessment\Models\PeriodPublication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The default state is `marks_open` at generation 1 with no pinned config
 * version - the state OpenAssessmentPeriod leaves the row in and the only one
 * `PublishPeriod` will accept as a starting point. Every other state in the
 * lifecycle is reached by running the real Action.
 *
 * Prerequisite academic rows go in through the query builder rather than the
 * Academics factories: this factory must not depend on another module's
 * factory API while both are being written.
 *
 * @extends Factory<PeriodPublication>
 */
final class PeriodPublicationFactory extends Factory
{
    protected $model = PeriodPublication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_period_id' => fn (): int => self::anyPeriodId(),
            'class_group_id' => fn (): int => self::anyClassGroupId(),
            'status' => PeriodPublication::STATUS_MARKS_OPEN,
            'snapshot_batch_id' => null,
            'generation' => 1,
            'report_card_config_version_id' => null,
            'version' => 1,
        ];
    }

    public function forClassGroup(int $classGroupId): self
    {
        return $this->state(fn (): array => ['class_group_id' => $classGroupId]);
    }

    public function forPeriod(int $periodId): self
    {
        return $this->state(fn (): array => ['assessment_period_id' => $periodId]);
    }

    private static function anyPeriodId(): int
    {
        $existing = DB::table('assessment_periods')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        /** @var Model $period */
        $period = AssessmentPeriodFactory::new()->create();

        return (int) $period->getKey();
    }

    private static function anyClassGroupId(): int
    {
        $existing = DB::table('class_groups')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        // Query builder rather than ClassGroupFactory: class_groups belongs to
        // Academics and that factory's contract is not this workstream's to
        // depend on while both are being written.
        return (int) DB::table('class_groups')->insertGetId([
            'class_level_id' => SubjectAllocationFactory::classLevelId(),
            'academic_year_id' => SubjectAllocationFactory::academicYearId(),
            'name' => 'Group '.Str::upper(Str::random(6)),
            'capacity' => 60,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
