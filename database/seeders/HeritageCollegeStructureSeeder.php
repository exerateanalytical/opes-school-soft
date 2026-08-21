<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Academics\Domain\AcademicYearStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Additive, idempotent structure fill-ins for the Heritage College demo:
 * - the missing Upper Sixth class_level + its class_group
 * - a second (previous) academic year for promotion/history testing
 * - Science/Arts/Commercial streams (subject_basket) wired to Upper Sixth
 *   class_groups, each with matching subject_allocations
 *
 * Every section guards on its own existence, so re-running this seeder is
 * safe alongside the other agents growing this same demo database.
 */
final class HeritageCollegeStructureSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->first();

        if ($admin !== null) {
            Auth::login($admin);
        }

        $sectionId = (int) DB::table('school_sections')->orderBy('id')->value('id');
        $currentYearId = (int) DB::table('academic_years')->where('code', '2026-2027')->value('id');

        $upperSixthId = $this->createUpperSixth($sectionId);
        $this->createPreviousAcademicYear();

        $streamIds = $this->createStreams($sectionId);
        $classGroupId = $this->createUpperSixthClassGroup($upperSixthId, $currentYearId, $streamIds['science']);

        $subjectIds = $this->subjectIdsByCode();
        $this->wireStreamSubjects($currentYearId, $upperSixthId, $streamIds, $subjectIds);

        $this->command?->info('Heritage College structure seeding complete.');
    }

    private function createUpperSixth(int $sectionId): int
    {
        $id = DB::table('class_levels')
            ->where('school_section_id', $sectionId)
            ->where('code', 'US')
            ->value('id');

        if (is_numeric($id)) {
            return (int) $id;
        }

        $maxOrder = (int) DB::table('class_levels')->where('school_section_id', $sectionId)->max('order_index');

        return (int) DB::table('class_levels')->insertGetId([
            'school_section_id' => $sectionId,
            'code' => 'US',
            'name' => 'Upper Sixth',
            'name_fr' => 'Terminale',
            'order_index' => $maxOrder + 1,
            'is_exam_class' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPreviousAcademicYear(): int
    {
        $existing = DB::table('academic_years')->where('code', '2025-2026')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return (int) DB::table('academic_years')->insertGetId([
            'code' => '2025-2026',
            'name' => 'Academic Year 2025/2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-08-31',
            'is_current' => false,
            'status' => AcademicYearStatus::Closed->value ?? 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function createStreams(int $sectionId): array
    {
        $baskets = [
            'science' => [
                'code' => 'SCI',
                'name' => 'Science',
                'name_fr' => 'Scientifique',
                'subjects' => ['MATH', 'PHY', 'CHEM', 'BIO', 'ICT', 'ENG', 'FRE'],
            ],
            'arts' => [
                'code' => 'ART',
                'name' => 'Arts',
                'name_fr' => 'Litteraire',
                'subjects' => ['ENG', 'LIT', 'HIST', 'GEO', 'ECO', 'CIV', 'FRE'],
            ],
            'commercial' => [
                'code' => 'COM',
                'name' => 'Commercial',
                'name_fr' => 'Commerciale',
                'subjects' => ['ECO', 'MATH', 'ENG', 'FRE', 'ICT'],
            ],
        ];

        $ids = [];

        foreach ($baskets as $key => $basket) {
            $id = DB::table('streams')
                ->where('school_section_id', $sectionId)
                ->where('code', $basket['code'])
                ->value('id');

            if (! is_numeric($id)) {
                $id = DB::table('streams')->insertGetId([
                    'school_section_id' => $sectionId,
                    'code' => $basket['code'],
                    'name' => $basket['name'],
                    'name_fr' => $basket['name_fr'],
                    'subject_basket' => json_encode($basket['subjects']),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[$key] = (int) $id;
        }

        return $ids;
    }

    private function createUpperSixthClassGroup(int $classLevelId, int $academicYearId, int $scienceStreamId): int
    {
        $groupName = 'Upper Sixth Science';

        $id = DB::table('class_groups')
            ->where('academic_year_id', $academicYearId)
            ->where('class_level_id', $classLevelId)
            ->where('name', $groupName)
            ->value('id');

        if (is_numeric($id)) {
            return (int) $id;
        }

        $teacherId = (int) DB::table('staff_members')->orderBy('id')->value('id');

        return (int) DB::table('class_groups')->insertGetId([
            'class_level_id' => $classLevelId,
            'stream_id' => $scienceStreamId,
            'academic_year_id' => $academicYearId,
            'name' => $groupName,
            'class_teacher_staff_id' => $teacherId > 0 ? $teacherId : null,
            'room_id' => null,
            'capacity' => 60,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function subjectIdsByCode(): array
    {
        return DB::table('subjects')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<string, int>  $streamIds
     * @param  array<string, int>  $subjectIds
     */
    private function wireStreamSubjects(int $academicYearId, int $classLevelId, array $streamIds, array $subjectIds): void
    {
        $baskets = [
            'science' => ['MATH', 'PHY', 'CHEM', 'BIO', 'ICT', 'ENG', 'FRE'],
            'arts' => ['ENG', 'LIT', 'HIST', 'GEO', 'ECO', 'CIV', 'FRE'],
            'commercial' => ['ECO', 'MATH', 'ENG', 'FRE', 'ICT'],
        ];

        foreach ($baskets as $key => $codes) {
            $streamId = $streamIds[$key] ?? null;

            if ($streamId === null) {
                continue;
            }

            foreach ($codes as $code) {
                $subjectId = $subjectIds[$code] ?? null;

                if ($subjectId === null) {
                    continue;
                }

                $exists = DB::table('subject_allocations')
                    ->where('academic_year_id', $academicYearId)
                    ->where('class_level_id', $classLevelId)
                    ->where('stream_id', $streamId)
                    ->where('subject_id', $subjectId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('subject_allocations')->insert([
                    'academic_year_id' => $academicYearId,
                    'class_level_id' => $classLevelId,
                    'stream_id' => $streamId,
                    'subject_id' => $subjectId,
                    'coefficient' => '2.00',
                    'subject_group_id' => null,
                    'required_components' => json_encode([]),
                    'max_score_override' => null,
                    'is_optional' => false,
                    'counts_toward_average' => true,
                    'effective_from_period_id' => null,
                    'effective_to_period_id' => null,
                    'is_active' => true,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
