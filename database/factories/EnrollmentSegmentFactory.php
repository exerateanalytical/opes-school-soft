<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Students\Domain\SegmentReason;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The default state is an OPEN `initial` segment, because that is the only
 * shape EnrollStudent ever creates (07-students 5.2) and every other shape is
 * reached from it by a transfer.
 *
 * Note that two open segments for one enrollment cannot be built even here:
 * uq_segment_open rejects the second insert. That is the point of the index.
 *
 * @extends Factory<EnrollmentSegment>
 */
class EnrollmentSegmentFactory extends Factory
{
    /** @var class-string<EnrollmentSegment> */
    protected $model = EnrollmentSegment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => fn (): int => (int) Enrollment::factory()->create()->getKey(),
            'class_group_id' => fn (): int => $this->classGroupId(),
            'starts_on' => '2026-09-05',
            'ends_on' => null,
            'roll_number' => null,
            'reason' => SegmentReason::Initial,
            'capacity_override' => false,
        ];
    }

    public function closedOn(string $endsOn): self
    {
        return $this->state(fn (): array => ['ends_on' => $endsOn]);
    }

    /**
     * Prerequisite reference rows go in through the query builder rather than
     * the Academics factories: those belong to another workstream.
     */
    private function classGroupId(): int
    {
        $existing = DB::table('class_groups')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        $sectionId = DB::table('school_sections')->insertGetId([
            'education_level' => 'secondary_1',
            'track' => 'general',
            'sub_system' => 'anglophone',
            'name' => 'Section '.Str::upper(Str::random(6)),
            'name_fr' => 'Section '.Str::upper(Str::random(6)),
            'matricule_format' => 'OS-{YY}-{NNNN}',
            'display_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $levelId = DB::table('class_levels')->insertGetId([
            'school_section_id' => $sectionId,
            'code' => 'F'.Str::upper(Str::random(4)),
            'name' => 'Form 1',
            'name_fr' => 'Sixieme',
            'order_index' => 1,
            'is_exam_class' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $yearId = DB::table('academic_years')->value('id');

        if (! is_numeric($yearId)) {
            $yearId = DB::table('academic_years')->insertGetId([
                'code' => '2026-2027-'.Str::lower(Str::random(6)),
                'name' => 'Academic Year 2026/2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-07-31',
                'is_current' => false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return DB::table('class_groups')->insertGetId([
            'class_level_id' => $levelId,
            'academic_year_id' => (int) $yearId,
            'name' => 'Group '.Str::upper(Str::random(8)),
            'capacity' => 60,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
