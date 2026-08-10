<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Academics\Domain\EducationLevel;
use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Academics\Domain\Track;
use App\Modules\Students\Actions\EnrollStudent;
use App\Modules\Students\Domain\Gender as StudentGender;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Extends DemoDataSeeder with authoritative Cameroonian secondary curriculum
 * reference data: the Anglophone Second Cycle (A-Level) section with its
 * Science/Arts/Commercial streams, and the Francophone First and Second
 * Cycle sections, plus the subjects and subject_allocations that go with
 * them.
 *
 * Modelling note: EducationLevel/Track/SubSystem only carry
 * general/technical/normal for `track` - there is no Science/Arts/Commercial
 * value in that enum. Those specialisations are modelled the same way the
 * schema already models them elsewhere: as `streams` rows (with a
 * subject_basket) under a `general` school_section, exactly like
 * DemoDataSeeder's existing Anglophone First Cycle section. This seeder
 * follows that precedent rather than inventing new enum cases.
 *
 * Idempotency strategy matches DemoDataSeeder: firstOrCreate/where-exists
 * guards at every insertion point instead of one outer transaction, so a
 * partial re-run converges instead of rolling back everything already built.
 */
final class CameroonianCurriculumSeeder extends Seeder
{
    /** @var list<string> */
    private array $maleFirstNames = [
        'Divine', 'Christian', 'Alain', 'Romeo', 'Marcel', 'Innocent', 'Rodrigue',
        'Elvis', 'Franklin', 'Cyrille',
    ];

    /** @var list<string> */
    private array $femaleFirstNames = [
        'Precious', 'Ornella', 'Ginette', 'Flore', 'Berthe', 'Christelle',
        'Judith', 'Cynthia', 'Merveille', 'Adele',
    ];

    /** @var list<string> */
    private array $lastNames = [
        'Abanda', 'Nkolo', 'Simo', 'Tabi', 'Eyenga', 'Meka', 'Ondoa',
        'Nana', 'Zambo', 'Kenmoe',
    ];

    private ?int $academicYearId = null;

    public function run(): void
    {
        $admin = \App\Modules\Identity\Models\User::query()->where('email', 'demo.admin@opeschool.test')->first();

        if ($admin !== null) {
            \Illuminate\Support\Facades\Auth::login($admin);
        }

        $this->academicYearId = (int) DB::table('academic_years')->where('code', '2026-2027')->value('id');

        if ($this->academicYearId === 0) {
            throw new \RuntimeException('Run DemoDataSeeder first: no 2026-2027 academic year found.');
        }

        $subjectIds = $this->createSubjects();
        $staffIds = DB::table('staff_members')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // --- Anglophone Second Cycle (Lower/Upper Sixth, A-Level) -------
        $angSecondCycleId = $this->createSchoolSection(
            EducationLevel::SecondarySecondCycle,
            Track::General,
            SubSystem::Anglophone,
            'Anglophone General Secondary (Second Cycle)',
            'Deuxieme cycle secondaire general anglophone',
            2,
        );

        $angStreamIds = $this->createStreams($angSecondCycleId, [
            ['code' => 'SCI', 'name' => 'Science', 'name_fr' => 'Scientifique'],
            ['code' => 'ART', 'name' => 'Arts', 'name_fr' => 'Litteraire'],
            ['code' => 'COM', 'name' => 'Commercial', 'name_fr' => 'Commerciale'],
        ], $subjectIds);

        $angLevelIds = $this->createClassLevels($angSecondCycleId, [
            ['code' => 'LS', 'name' => 'Lower Sixth', 'name_fr' => 'Premiere', 'exam' => false],
            ['code' => 'US', 'name' => 'Upper Sixth', 'name_fr' => 'Terminale', 'exam' => true],
        ]);

        $angGroupIds = $this->createStreamedClassGroups($angLevelIds, $angStreamIds, $staffIds);

        $this->createStreamedSubjectAllocations($angLevelIds, $angStreamIds, $subjectIds);

        // --- Francophone First Cycle (6eme - 3eme) -----------------------
        $freFirstCycleId = $this->createSchoolSection(
            EducationLevel::SecondaryFirstCycle,
            Track::General,
            SubSystem::Francophone,
            'Francophone General Secondary (First Cycle)',
            'Premier cycle secondaire general francophone',
            3,
        );

        $freFirstLevelIds = $this->createClassLevels($freFirstCycleId, [
            ['code' => '6E', 'name' => 'Form 1 (6eme)', 'name_fr' => 'Sixieme', 'exam' => false],
            ['code' => '5E', 'name' => 'Form 2 (5eme)', 'name_fr' => 'Cinquieme', 'exam' => false],
            ['code' => '4E', 'name' => 'Form 3 (4eme)', 'name_fr' => 'Quatrieme', 'exam' => false],
            ['code' => '3E', 'name' => 'Form 4 (3eme)', 'name_fr' => 'Troisieme', 'exam' => true],
        ]);

        $freFirstGroupIds = $this->createPlainClassGroups($freFirstLevelIds, $staffIds);

        $this->createFlatSubjectAllocations($freFirstLevelIds, array_slice($subjectIds, 0, 12));

        // --- Francophone Second Cycle (2nde - Tle) ------------------------
        $freSecondCycleId = $this->createSchoolSection(
            EducationLevel::SecondarySecondCycle,
            Track::General,
            SubSystem::Francophone,
            'Francophone General Secondary (Second Cycle)',
            'Deuxieme cycle secondaire general francophone',
            4,
        );

        $freStreamIds = $this->createStreams($freSecondCycleId, [
            ['code' => 'SCF', 'name' => 'Scientifique', 'name_fr' => 'Scientifique'],
            ['code' => 'LTF', 'name' => 'Litteraire', 'name_fr' => 'Litteraire'],
            ['code' => 'CMF', 'name' => 'Commerciale', 'name_fr' => 'Commerciale'],
        ], $subjectIds);

        $freSecondLevelIds = $this->createClassLevels($freSecondCycleId, [
            ['code' => '2ND', 'name' => 'Seconde', 'name_fr' => 'Seconde', 'exam' => false],
            ['code' => '1ER', 'name' => 'Premiere', 'name_fr' => 'Premiere', 'exam' => false],
            ['code' => 'TLE', 'name' => 'Terminale', 'name_fr' => 'Terminale', 'exam' => true],
        ]);

        $freSecondGroupIds = $this->createStreamedClassGroups($freSecondLevelIds, $freStreamIds, $staffIds);

        $this->createStreamedSubjectAllocations($freSecondLevelIds, $freStreamIds, $subjectIds);

        // --- New demo students into the new class groups ------------------
        $allNewGroupIds = array_values(array_unique(array_merge(
            $angGroupIds,
            $freFirstGroupIds,
            $freSecondGroupIds,
        )));

        $this->enrollNewStudents($allNewGroupIds);

        $this->command?->info('Cameroonian curriculum seeding complete.');
    }

    private function createSchoolSection(
        EducationLevel $level,
        Track $track,
        SubSystem $subSystem,
        string $name,
        string $nameFr,
        int $displayOrder,
    ): int {
        $existing = DB::table('school_sections')
            ->where('education_level', $level->value)
            ->where('track', $track->value)
            ->where('sub_system', $subSystem->value)
            ->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return (int) DB::table('school_sections')->insertGetId([
            'education_level' => $level->value,
            'track' => $track->value,
            'sub_system' => $subSystem->value,
            'name' => $name,
            'name_fr' => $nameFr,
            'matricule_format' => 'OS-{YY}-{NNNN}',
            'display_order' => $displayOrder,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array{code: string, name: string, name_fr: string}>  $streams
     * @param  array<string, int>  $subjectIds
     * @return array<string, int> code => stream id
     */
    private function createStreams(int $sectionId, array $streams, array $subjectIds): array
    {
        $baskets = [
            'SCI' => ['MATH', 'FMATH', 'PHY', 'CHEM', 'BIO'],
            'ART' => ['LIT', 'HIST', 'GEO', 'PHIL', 'FRE'],
            'COM' => ['ACC', 'BUS', 'ECO', 'MATH', 'ENG'],
            'SCF' => ['MATH', 'PCF', 'SVT', 'FRE'],
            'LTF' => ['PHIF', 'LITF', 'HISF', 'FRE'],
            'CMF' => ['COMF', 'ECOF', 'MATH', 'FRE'],
        ];

        $ids = [];

        foreach ($streams as $index => $stream) {
            $id = DB::table('streams')
                ->where('school_section_id', $sectionId)
                ->where('code', $stream['code'])
                ->value('id');

            if (! is_numeric($id)) {
                $basketCodes = $baskets[$stream['code']] ?? [];
                $basketSubjectIds = array_values(array_filter(array_map(
                    fn (string $code): ?int => $subjectIds[$code] ?? null,
                    $basketCodes,
                )));

                $id = DB::table('streams')->insertGetId([
                    'school_section_id' => $sectionId,
                    'code' => $stream['code'],
                    'name' => $stream['name'],
                    'name_fr' => $stream['name_fr'],
                    'subject_basket' => json_encode($basketSubjectIds),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[$stream['code']] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param  list<array{code: string, name: string, name_fr: string, exam: bool}>  $levels
     * @return list<int>
     */
    private function createClassLevels(int $sectionId, array $levels): array
    {
        $ids = [];

        foreach ($levels as $index => $level) {
            $id = DB::table('class_levels')
                ->where('school_section_id', $sectionId)
                ->where('code', $level['code'])
                ->value('id');

            if (! is_numeric($id)) {
                $id = DB::table('class_levels')->insertGetId([
                    'school_section_id' => $sectionId,
                    'code' => $level['code'],
                    'name' => $level['name'],
                    'name_fr' => $level['name_fr'],
                    'order_index' => $index + 1,
                    'is_exam_class' => $level['exam'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * One class group per (level x stream), e.g. "Lower Sixth Science".
     *
     * @param  list<int>  $classLevelIds
     * @param  array<string, int>  $streamIds
     * @param  list<int>  $staffIds
     * @return list<int>
     */
    private function createStreamedClassGroups(array $classLevelIds, array $streamIds, array $staffIds): array
    {
        $ids = [];
        $staffCursor = 0;
        $hasStaff = $staffIds !== [];

        foreach ($classLevelIds as $classLevelId) {
            $level = DB::table('class_levels')->where('id', $classLevelId)->first();
            $levelName = is_object($level) ? (string) $level->name : 'Class';

            foreach ($streamIds as $streamCode => $streamId) {
                $stream = DB::table('streams')->where('id', $streamId)->first();
                $streamName = is_object($stream) ? (string) $stream->name : $streamCode;
                $groupName = $levelName.' '.$streamName;

                $id = DB::table('class_groups')
                    ->where('academic_year_id', $this->academicYearId)
                    ->where('class_level_id', $classLevelId)
                    ->where('name', $groupName)
                    ->value('id');

                if (! is_numeric($id)) {
                    $teacherId = $hasStaff ? $staffIds[$staffCursor % count($staffIds)] : null;
                    $staffCursor++;

                    $id = DB::table('class_groups')->insertGetId([
                        'class_level_id' => $classLevelId,
                        'stream_id' => $streamId,
                        'academic_year_id' => $this->academicYearId,
                        'name' => $groupName,
                        'class_teacher_staff_id' => $teacherId,
                        'room_id' => null,
                        'capacity' => 50,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $classLevelIds
     * @param  list<int>  $staffIds
     * @return list<int>
     */
    private function createPlainClassGroups(array $classLevelIds, array $staffIds): array
    {
        $ids = [];
        $streamLetters = ['A', 'B'];
        $staffCursor = 0;
        $hasStaff = $staffIds !== [];

        foreach ($classLevelIds as $classLevelId) {
            $level = DB::table('class_levels')->where('id', $classLevelId)->first();
            $levelName = is_object($level) ? (string) $level->name : 'Class';

            foreach ($streamLetters as $letter) {
                $groupName = $levelName.' '.$letter;

                $id = DB::table('class_groups')
                    ->where('academic_year_id', $this->academicYearId)
                    ->where('class_level_id', $classLevelId)
                    ->where('name', $groupName)
                    ->value('id');

                if (! is_numeric($id)) {
                    $teacherId = $hasStaff ? $staffIds[$staffCursor % count($staffIds)] : null;
                    $staffCursor++;

                    $id = DB::table('class_groups')->insertGetId([
                        'class_level_id' => $classLevelId,
                        'stream_id' => null,
                        'academic_year_id' => $this->academicYearId,
                        'name' => $groupName,
                        'class_teacher_staff_id' => $teacherId,
                        'room_id' => null,
                        'capacity' => 60,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, int> code => subject id
     */
    private function createSubjects(): array
    {
        $subjects = [
            // Science-track extras
            ['code' => 'FMATH', 'name' => 'Further Mathematics', 'name_fr' => 'Mathematiques Approfondies'],
            ['code' => 'ADDMATH', 'name' => 'Additional Mathematics', 'name_fr' => 'Mathematiques Complementaires'],
            ['code' => 'COMP', 'name' => 'Computer Science', 'name_fr' => 'Sciences Informatiques'],
            ['code' => 'GEOL', 'name' => 'Geology', 'name_fr' => 'Geologie'],
            // Arts/Literary track extras
            ['code' => 'PHIL', 'name' => 'Philosophy', 'name_fr' => 'Philosophie'],
            ['code' => 'LITEN', 'name' => 'General Literature', 'name_fr' => 'Litterature Generale'],
            ['code' => 'GOVT', 'name' => 'Government', 'name_fr' => 'Institutions Politiques'],
            // Commercial/Business track extras
            ['code' => 'ACC', 'name' => 'Accounting', 'name_fr' => 'Comptabilite'],
            ['code' => 'BUS', 'name' => 'Business Management', 'name_fr' => 'Gestion des Entreprises'],
            ['code' => 'COMM', 'name' => 'Commerce', 'name_fr' => 'Commerce'],
            ['code' => 'TYPE', 'name' => 'Typewriting & Office Practice', 'name_fr' => 'Dactylographie et Bureautique'],
            // Francophone-specific naming (kept distinct from anglophone
            // codes above so both curricula can be allocated independently)
            ['code' => 'PCF', 'name' => 'Physique-Chimie', 'name_fr' => 'Physique-Chimie'],
            ['code' => 'SVT', 'name' => 'Sciences de la Vie et de la Terre', 'name_fr' => 'Sciences de la Vie et de la Terre'],
            ['code' => 'PHIF', 'name' => 'Philosophie (Programme Francophone)', 'name_fr' => 'Philosophie'],
            ['code' => 'LITF', 'name' => 'Litterature Francaise', 'name_fr' => 'Litterature Francaise'],
            ['code' => 'HISF', 'name' => 'Histoire (Programme Francophone)', 'name_fr' => 'Histoire'],
            ['code' => 'COMF', 'name' => 'Techniques Commerciales', 'name_fr' => 'Techniques Commerciales'],
            ['code' => 'ECOF', 'name' => 'Economie (Programme Francophone)', 'name_fr' => 'Economie'],
            // Shared reference subjects not yet in DemoDataSeeder's list
            ['code' => 'SPAN', 'name' => 'Spanish', 'name_fr' => 'Espagnol'],
            ['code' => 'GERM', 'name' => 'German', 'name_fr' => 'Allemand'],
        ];

        $ids = [];

        // Pull in DemoDataSeeder's subject codes too so streams/allocations
        // below can reference core subjects (MATH, PHY, CHEM, BIO, LIT,
        // HIST, GEO, FRE, ENG) by code without recreating them.
        $existing = DB::table('subjects')->pluck('id', 'code');

        foreach ($existing as $code => $id) {
            $ids[(string) $code] = (int) $id;
        }

        foreach ($subjects as $subject) {
            $id = DB::table('subjects')->where('code', $subject['code'])->value('id');

            if (! is_numeric($id)) {
                $id = DB::table('subjects')->insertGetId([
                    'code' => $subject['code'],
                    'name' => $subject['name'],
                    'name_fr' => $subject['name_fr'],
                    'department_id' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[$subject['code']] = (int) $id;
        }

        return $ids;
    }

    /**
     * Allocates each stream's subject basket to every level in the cycle,
     * scoped by stream_id so Science students are not given Philosophy and
     * vice versa.
     *
     * @param  list<int>  $classLevelIds
     * @param  array<string, int>  $streamIds
     * @param  array<string, int>  $subjectIds
     */
    private function createStreamedSubjectAllocations(array $classLevelIds, array $streamIds, array $subjectIds): void
    {
        $baskets = [
            'SCI' => ['MATH', 'FMATH', 'PHY', 'CHEM', 'BIO', 'ENG', 'FRE', 'COMP'],
            'ART' => ['LIT', 'HIST', 'GEO', 'PHIL', 'FRE', 'ENG', 'GOVT', 'LITEN'],
            'COM' => ['ACC', 'BUS', 'ECO', 'COMM', 'MATH', 'ENG', 'FRE'],
            'SCF' => ['MATH', 'PCF', 'SVT', 'FRE', 'ENG'],
            'LTF' => ['PHIF', 'LITF', 'HISF', 'FRE', 'ENG'],
            'CMF' => ['COMF', 'ECOF', 'MATH', 'FRE', 'ENG'],
        ];

        foreach ($classLevelIds as $classLevelId) {
            foreach ($streamIds as $code => $streamId) {
                $subjectCodes = $baskets[$code] ?? [];

                foreach ($subjectCodes as $subjectCode) {
                    $subjectId = $subjectIds[$subjectCode] ?? null;

                    if ($subjectId === null) {
                        continue;
                    }

                    $exists = DB::table('subject_allocations')
                        ->where('academic_year_id', $this->academicYearId)
                        ->where('class_level_id', $classLevelId)
                        ->where('stream_id', $streamId)
                        ->where('subject_id', $subjectId)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('subject_allocations')->insert([
                        'academic_year_id' => $this->academicYearId,
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

    /**
     * Non-streamed allocation (stream_id sentinel 0), for levels with no
     * stream split (Francophone First Cycle).
     *
     * @param  list<int>  $classLevelIds
     * @param  list<int>  $subjectIds
     */
    private function createFlatSubjectAllocations(array $classLevelIds, array $subjectIds): void
    {
        foreach ($classLevelIds as $classLevelId) {
            foreach ($subjectIds as $subjectId) {
                $exists = DB::table('subject_allocations')
                    ->where('academic_year_id', $this->academicYearId)
                    ->where('class_level_id', $classLevelId)
                    ->where('stream_id', 0)
                    ->where('subject_id', $subjectId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('subject_allocations')->insert([
                    'academic_year_id' => $this->academicYearId,
                    'class_level_id' => $classLevelId,
                    'stream_id' => 0,
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

    /**
     * @param  list<int>  $classGroupIds
     */
    private function enrollNewStudents(array $classGroupIds): void
    {
        if ($classGroupIds === []) {
            return;
        }

        $target = 15;
        $existingCount = Student::query()->where('matricule', 'like', 'OS-26-CUR%')->count();
        $toCreate = max(0, $target - $existingCount);

        for ($i = 0; $i < $toCreate; $i++) {
            $gender = $i % 2 === 0 ? StudentGender::Male : StudentGender::Female;
            $firstName = $gender === StudentGender::Male
                ? $this->maleFirstNames[$i % count($this->maleFirstNames)]
                : $this->femaleFirstNames[$i % count($this->femaleFirstNames)];
            $lastName = $this->lastNames[$i % count($this->lastNames)];

            $sequence = $existingCount + $i + 1;
            $matricule = sprintf('OS-26-CUR%04d', $sequence);
            $admissionNo = sprintf('HA/ADM/2026/CUR%04d', $sequence);

            if (Student::query()->where('matricule', $matricule)->exists()) {
                continue;
            }

            $student = Student::query()->create([
                'matricule' => $matricule,
                'matricule_is_official' => true,
                'admission_no' => $admissionNo,
                'first_name' => $firstName,
                'middle_name' => null,
                'last_name' => $lastName,
                'preferred_name' => null,
                'date_of_birth' => sprintf('20%02d-0%d-1%d', 6 + ($i % 8), 1 + ($i % 9), $i % 8),
                'birth_certificate_no' => null,
                'place_of_birth' => 'Yaounde',
                'gender' => $gender,
                'nationality' => 'CM',
                'state_of_origin' => ['Centre', 'Littoral', 'North West', 'South West', 'West', 'Far North'][$i % 6],
                'religion' => null,
                'blood_group' => null,
                'genotype' => null,
                'national_id_number' => null,
                'national_id_blind_index' => null,
                'photo_path' => null,
                'phone' => null,
                'email' => null,
                'address_line' => null,
                'city' => null,
                'region' => null,
                'house_id' => null,
                'status' => StudentStatus::Active,
                'first_admission_date' => '2026-09-05',
                'left_on' => null,
                'deceased_on' => null,
                'is_archived' => false,
                'created_by' => null,
                'updated_by' => null,
            ]);

            $classGroupId = $classGroupIds[$i % count($classGroupIds)];

            app(EnrollStudent::class)->handle(
                studentId: (int) $student->id,
                academicYearId: (int) $this->academicYearId,
                classGroupId: $classGroupId,
                enrolledOn: '2026-09-05',
            );
        }
    }
}
