<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\EnrollmentType;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prerequisite rows (student, academic year, class level, section) are
 * inserted with DB::table() rather than through their own factories,
 * deliberately: those factories belong to other Phase 2 workstreams and this
 * one must not depend on their code to stay green.
 *
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    /** @var class-string<Enrollment> */
    protected $model = Enrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => fn (): int => $this->studentId(),
            'academic_year_id' => fn (): int => $this->academicYearId(),
            'class_level_id' => fn (): int => $this->classLevelId(),
            'stream_id' => null,
            'school_section_id' => fn (): int => $this->schoolSectionId(),
            'status' => EnrollmentStatus::Active,
            'is_repeat' => false,
            'enrollment_type' => EnrollmentType::New,
            'enrolled_on' => '2026-09-05',
            'boarding_status' => 'day',
            'financial_clearance' => false,
        ];
    }

    public function withdrawn(string $on = '2027-01-15'): self
    {
        // 4.2 invariant 3: a terminal status and left_on are set together.
        return $this->state(fn (): array => [
            'status' => EnrollmentStatus::Withdrawn,
            'left_on' => $on,
        ]);
    }

    private function studentId(): int
    {
        $suffix = Str::upper(Str::random(8));

        return DB::table('students')->insertGetId([
            'matricule' => 'OS-26-'.$suffix,
            'matricule_is_official' => true,
            'admission_no' => 'HA/ADM/2026/'.$suffix,
            'first_name' => 'Test',
            'last_name' => 'Student '.$suffix,
            'date_of_birth' => '2012-04-11',
            'place_of_birth' => 'Bamenda',
            'gender' => 'male',
            'nationality' => 'CM',
            'status' => 'prospective',
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function academicYearId(): int
    {
        $existing = DB::table('academic_years')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return DB::table('academic_years')->insertGetId([
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

    private function schoolSectionId(): int
    {
        $existing = DB::table('school_sections')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return DB::table('school_sections')->insertGetId([
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

    private function classLevelId(): int
    {
        $existing = DB::table('class_levels')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return DB::table('class_levels')->insertGetId([
            'school_section_id' => $this->schoolSectionId(),
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
