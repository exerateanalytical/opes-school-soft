<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\ClassGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prerequisite rows (academic year, school section, class level) are inserted
 * with DB::table() rather than their factories, deliberately: those factories
 * belong to other Academics workstreams and this factory must not depend on
 * their code to stay green.
 *
 * @extends Factory<ClassGroup>
 */
class ClassGroupFactory extends Factory
{
    /** @var class-string<ClassGroup> */
    protected $model = ClassGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_level_id' => fn (): int => $this->classLevelId(),
            'stream_id' => null,
            'academic_year_id' => fn (): int => $this->academicYearId(),
            'name' => 'Group '.Str::upper(Str::random(8)),
            'class_teacher_staff_id' => null,
            'room_id' => null,
            'capacity' => 60,
            'status' => 'active',
        ];
    }

    private function academicYearId(): int
    {
        $existing = DB::table('academic_years')->value('id');

        if (is_int($existing)) {
            return $existing;
        }

        return DB::table('academic_years')->insertGetId([
            'code' => '2026-2027-'.Str::lower(Str::random(6)),
            'name' => 'Academic Year 2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_current' => false,
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function classLevelId(): int
    {
        $existing = DB::table('class_levels')->value('id');

        if (is_int($existing)) {
            return $existing;
        }

        $sectionId = DB::table('school_sections')->value('id');

        if (! is_int($sectionId)) {
            $sectionId = DB::table('school_sections')->insertGetId([
                'education_level' => 'secondary_1',
                'track' => 'general',
                'sub_system' => 'anglophone',
                'name' => 'Anglophone General Secondary (First Cycle)',
                'name_fr' => 'Premier cycle secondaire general anglophone',
                'matricule_format' => 'OS-{YY}-{NNNN}',
                'display_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return DB::table('class_levels')->insertGetId([
            'school_section_id' => $sectionId,
            'code' => 'F'.Str::upper(Str::random(4)),
            'name' => 'Form 1',
            'name_fr' => 'Sixieme',
            'order_index' => 1,
            'is_exam_class' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
