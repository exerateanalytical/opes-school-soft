<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Academics\Domain\AcademicYearStatus;
use App\Modules\Academics\Domain\EducationLevel;
use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Academics\Domain\Track;
use App\Modules\Fees\Actions\GenerateInvoices;
use App\Modules\Fees\Actions\IssueInvoice;
use App\Modules\Fees\Actions\RecordPayment;
use App\Modules\Fees\Domain\FeeBearer;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Students\Actions\EnrollStudent;
use App\Modules\Students\Domain\Gender as StudentGender;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotency strategy: firstOrCreate/where-exists guards at every insertion
 * point (not a single outer DB::transaction). A demo dataset is meant to be
 * grown incrementally across re-runs during development - wrapping the whole
 * seeder in one transaction would mean a late failure (e.g. an Action
 * throwing on a precondition) rolls back everything already built in this
 * run, which is the opposite of what you want while iterating on a seeder.
 * Each section is safe to re-run: it looks for its own rows before creating
 * more.
 */
final class DemoDataSeeder extends Seeder
{
    /** @var list<string> */
    private array $maleFirstNames = [
        'Jean', 'Paul', 'Emmanuel', 'Achille', 'Bertrand', 'Ferdinand', 'Serge',
        'Yves', 'Patrick', 'Aristide', 'Boris', 'Cedric', 'Landry', 'Herve',
        'Blaise', 'Arnaud', 'Junior', 'Frank',
    ];

    /** @var list<string> */
    private array $femaleFirstNames = [
        'Marie', 'Grace', 'Aminatou', 'Solange', 'Carine', 'Josiane', 'Nadege',
        'Larissa', 'Odette', 'Chantal', 'Reine', 'Aurelie', 'Pulcherie',
        'Delphine', 'Estelle', 'Vanessa', 'Brenda', 'Sandrine',
    ];

    /** @var list<string> */
    private array $lastNames = [
        'Ngwa', 'Fotso', 'Mballa', 'Njoya', 'Atangana', 'Kamga', 'Tchoumi',
        'Biya', 'Ndongo', 'Fokou', 'Talla', 'Etoundi', 'Mvondo', 'Nkeng',
        'Wamba', 'Sonkeng', 'Ekwalla', 'Bassong', 'Nguemo', 'Djoumessi',
    ];

    private ?int $academicYearId = null;

    private ?int $schoolSectionId = null;

    private ?int $fiscalYearId = null;

    public function run(): void
    {
        $admin = $this->demoAdmin();
        \Illuminate\Support\Facades\Auth::login($admin);

        $this->academicYearId = $this->createAcademicYear();
        $this->schoolSectionId = $this->createSchoolSection();

        $classLevelIds = $this->createClassLevels($this->schoolSectionId);
        $staffIds = $this->createStaff();
        $classGroupIds = $this->createClassGroups($classLevelIds, $staffIds);
        $subjectIds = $this->createSubjects();
        $this->createSubjectAllocations($classLevelIds, $subjectIds);

        $this->fiscalYearId = $this->createFiscalYearAndPeriod();

        $enrollmentIds = $this->createStudentsWithGuardiansAndEnrollments($classGroupIds);

        $structureId = $this->createFeeStructure();
        $this->generateAndCollectInvoices($structureId, $enrollmentIds);

        $this->command?->info('Demo data seeding complete.');
    }

    private function createAcademicYear(): int
    {
        $existing = DB::table('academic_years')->where('code', '2026-2027')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return (int) DB::table('academic_years')->insertGetId([
            'code' => '2026-2027',
            'name' => 'Academic Year 2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_current' => true,
            'status' => AcademicYearStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchoolSection(): int
    {
        $existing = DB::table('school_sections')
            ->where('education_level', EducationLevel::SecondaryFirstCycle->value)
            ->where('track', Track::General->value)
            ->where('sub_system', SubSystem::Anglophone->value)
            ->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return (int) DB::table('school_sections')->insertGetId([
            'education_level' => EducationLevel::SecondaryFirstCycle->value,
            'track' => Track::General->value,
            'sub_system' => SubSystem::Anglophone->value,
            'name' => 'Anglophone General Secondary (First Cycle)',
            'name_fr' => 'Premier cycle secondaire general anglophone',
            'matricule_format' => 'OS-{YY}-{NNNN}',
            'display_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<int>
     */
    private function createClassLevels(int $sectionId): array
    {
        $levels = [
            ['code' => 'F1', 'name' => 'Form 1', 'name_fr' => 'Sixieme'],
            ['code' => 'F2', 'name' => 'Form 2', 'name_fr' => 'Cinquieme'],
            ['code' => 'F3', 'name' => 'Form 3', 'name_fr' => 'Quatrieme'],
            ['code' => 'F4', 'name' => 'Form 4', 'name_fr' => 'Troisieme'],
            ['code' => 'F5', 'name' => 'Form 5', 'name_fr' => 'Seconde'],
            ['code' => 'LS', 'name' => 'Lower Sixth', 'name_fr' => 'Premiere'],
        ];

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
                    'is_exam_class' => $level['code'] === 'F4',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function createStaff(): array
    {
        $ids = [];
        $count = 18;

        for ($i = 0; $i < $count; $i++) {
            $gender = $i % 2 === 0 ? 'male' : 'female';
            $firstName = $gender === 'male'
                ? $this->maleFirstNames[$i % count($this->maleFirstNames)]
                : $this->femaleFirstNames[$i % count($this->femaleFirstNames)];
            $lastName = $this->lastNames[$i % count($this->lastNames)];
            $staffNo = sprintf('STF-2026-%03d', $i + 1);

            $id = DB::table('staff_members')->where('staff_no', $staffNo)->value('id');

            if (! is_numeric($id)) {
                $id = DB::table('staff_members')->insertGetId([
                    'staff_no' => $staffNo,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'other_names' => null,
                    'gender' => $gender,
                    'date_of_birth' => sprintf('19%d-0%d-1%d', 70 + ($i % 20), 1 + ($i % 9), $i % 8),
                    'place_of_birth' => 'Bamenda',
                    'nationality' => 'CM',
                    'phone' => sprintf('+237 6%08d', 70000000 + $i),
                    'email' => null,
                    'photo_path' => null,
                    'status' => 'active',
                    'is_archived' => false,
                    'cnps_registration_status' => 'not_required',
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $classLevelIds
     * @param  list<int>  $staffIds
     * @return list<int>
     */
    private function createClassGroups(array $classLevelIds, array $staffIds): array
    {
        $ids = [];
        $streamNames = ['A', 'B'];
        $staffCursor = 0;

        foreach ($classLevelIds as $levelIndex => $classLevelId) {
            // ~2 streams per level except the last, to land in the 10-15 range.
            $streamsForLevel = $levelIndex < 4 ? $streamNames : ['A'];

            foreach ($streamsForLevel as $streamLetter) {
                $level = DB::table('class_levels')->where('id', $classLevelId)->first();
                $levelName = is_object($level) ? (string) $level->name : 'Class';
                $groupName = $levelName.' '.$streamLetter;

                $id = DB::table('class_groups')
                    ->where('academic_year_id', $this->academicYearId)
                    ->where('class_level_id', $classLevelId)
                    ->where('name', $groupName)
                    ->value('id');

                if (! is_numeric($id)) {
                    $teacherId = $staffIds[$staffCursor % count($staffIds)];
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
     * @return list<int>
     */
    private function createSubjects(): array
    {
        $subjects = [
            ['code' => 'MATH', 'name' => 'Mathematics', 'name_fr' => 'Mathematiques'],
            ['code' => 'ENG', 'name' => 'English Language', 'name_fr' => 'Anglais'],
            ['code' => 'FRE', 'name' => 'French', 'name_fr' => 'Francais'],
            ['code' => 'BIO', 'name' => 'Biology', 'name_fr' => 'Biologie'],
            ['code' => 'PHY', 'name' => 'Physics', 'name_fr' => 'Physique'],
            ['code' => 'CHEM', 'name' => 'Chemistry', 'name_fr' => 'Chimie'],
            ['code' => 'HIST', 'name' => 'History', 'name_fr' => 'Histoire'],
            ['code' => 'GEO', 'name' => 'Geography', 'name_fr' => 'Geographie'],
            ['code' => 'LIT', 'name' => 'Literature in English', 'name_fr' => 'Litterature'],
            ['code' => 'CIV', 'name' => 'Citizenship Education', 'name_fr' => 'Education Civique'],
            ['code' => 'ICT', 'name' => 'Information & Communication Technology', 'name_fr' => 'Informatique'],
            ['code' => 'PE', 'name' => 'Physical Education', 'name_fr' => 'Education Physique et Sportive'],
            ['code' => 'ECO', 'name' => 'Economics', 'name_fr' => 'Economie'],
            ['code' => 'FOOD', 'name' => 'Food & Nutrition', 'name_fr' => 'Alimentation et Nutrition'],
            ['code' => 'ART', 'name' => 'Fine Art', 'name_fr' => 'Arts Plastiques'],
            ['code' => 'MUS', 'name' => 'Music', 'name_fr' => 'Musique'],
            ['code' => 'REL', 'name' => 'Religious Studies', 'name_fr' => 'Education Religieuse'],
            ['code' => 'AGR', 'name' => 'Agricultural Science', 'name_fr' => 'Sciences Agricoles'],
        ];

        $ids = [];

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

            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $classLevelIds
     * @param  list<int>  $subjectIds
     */
    private function createSubjectAllocations(array $classLevelIds, array $subjectIds): void
    {
        // Core subjects taught at every level; a rotating handful of extras
        // per level round it out to a realistic timetable without wiring
        // every subject into every level.
        $coreCount = 10;
        $core = array_slice($subjectIds, 0, $coreCount);
        $extras = array_slice($subjectIds, $coreCount);

        foreach ($classLevelIds as $levelIndex => $classLevelId) {
            $levelSubjects = $core;
            $levelSubjects[] = $extras[$levelIndex % count($extras)];
            $levelSubjects[] = $extras[($levelIndex + 1) % count($extras)];

            foreach (array_unique($levelSubjects) as $subjectId) {
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

    private function createFiscalYearAndPeriod(): int
    {
        $fiscalYearId = DB::table('fiscal_years')->where('code', '2026')->value('id');

        if (! is_numeric($fiscalYearId)) {
            $fiscalYearId = DB::table('fiscal_years')->insertGetId([
                'code' => '2026',
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-12-31',
                'status' => 'open',
                'is_first_exercice' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $fiscalYearId = (int) $fiscalYearId;

        // Cover both the Sept invoice run and the Oct payment collections.
        foreach (['2026-09-01', '2026-10-01'] as $monthStart) {
            $start = \Illuminate\Support\Carbon::parse($monthStart);

            $exists = DB::table('accounting_periods')
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('period_month', $start->toDateString())
                ->exists();

            if (! $exists) {
                DB::table('accounting_periods')->insert([
                    'fiscal_year_id' => $fiscalYearId,
                    'period_month' => $start->toDateString(),
                    'starts_on' => $start->copy()->startOfMonth()->toDateString(),
                    'ends_on' => $start->copy()->endOfMonth()->toDateString(),
                    'status' => 'open',
                    'is_quarter_end' => false,
                    'forced_closure_due_on' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $fiscalYearId;
    }

    /**
     * @param  list<int>  $classGroupIds
     * @return list<int> enrollment ids
     */
    private function createStudentsWithGuardiansAndEnrollments(array $classGroupIds): array
    {
        $targetStudents = 100;
        $enrollmentIds = [];
        $actor = Actor::system();

        $existingCount = Student::query()->count();
        $toCreate = max(0, $targetStudents - $existingCount);

        // Even if students already exist (idempotent re-run), still collect
        // their enrollment ids for this academic year so invoicing can run.
        $existingEnrollmentIds = DB::table('enrollments')
            ->where('academic_year_id', $this->academicYearId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($existingEnrollmentIds as $id) {
            $enrollmentIds[] = $id;
        }

        for ($i = 0; $i < $toCreate; $i++) {
            $gender = $i % 2 === 0 ? StudentGender::Male : StudentGender::Female;
            $firstName = $gender === StudentGender::Male
                ? $this->maleFirstNames[($i + 3) % count($this->maleFirstNames)]
                : $this->femaleFirstNames[($i + 3) % count($this->femaleFirstNames)];
            $lastName = $this->lastNames[($i + 7) % count($this->lastNames)];

            $sequence = $existingCount + $i + 1;
            $matricule = sprintf('OS-26-%04d', $sequence);
            $admissionNo = sprintf('HA/ADM/2026/%04d', $sequence);

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
                'date_of_birth' => sprintf('20%02d-0%d-1%d', 8 + ($i % 10), 1 + ($i % 9), $i % 8),
                'birth_certificate_no' => null,
                'place_of_birth' => 'Douala',
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

            // 1-2 guardians per student.
            $guardianCount = $i % 3 === 0 ? 2 : 1;

            for ($g = 0; $g < $guardianCount; $g++) {
                $guardianGender = $g === 0 ? 'male' : 'female';
                $guardianFirst = $guardianGender === 'male'
                    ? $this->maleFirstNames[($i + $g + 5) % count($this->maleFirstNames)]
                    : $this->femaleFirstNames[($i + $g + 5) % count($this->femaleFirstNames)];

                /** @var Guardian $guardian */
                $guardian = Guardian::query()->create([
                    'guardian_no' => 'GRD-'.Str::upper(Str::random(8)),
                    'title' => $guardianGender === 'male' ? 'Mr.' : 'Mrs.',
                    'first_name' => $guardianFirst,
                    'last_name' => $lastName,
                    'date_of_birth' => sprintf('19%d-0%d-1%d', 75 + ($i % 15), 1 + ($g % 9), 1 + ($i % 8)),
                    'gender' => $guardianGender === 'male' ? \App\Modules\Guardians\Domain\Gender::Male : \App\Modules\Guardians\Domain\Gender::Female,
                    'nationality' => 'CM',
                    'id_type' => \App\Modules\Guardians\Domain\GuardianIdType::NationalId,
                    'id_number' => null,
                    'id_number_blind_index' => null,
                    'occupation' => 'Trader',
                    'employer' => null,
                    'marital_status' => \App\Modules\Guardians\Domain\MaritalStatus::Married,
                    'phone' => \App\Modules\Guardians\Domain\PhoneNumber::normalise('6'.random_int(10000000, 99999999)),
                    'alternative_phone' => null,
                    'email' => null,
                    'address_line' => 'Rue '.($i + 1),
                    'city' => 'Douala',
                    'region' => 'Littoral',
                    'country' => 'Cameroon',
                    'residential_status' => \App\Modules\Guardians\Domain\ResidentialStatus::OwnHouse,
                    'preferred_contact_method' => \App\Modules\Guardians\Domain\PreferredContactMethod::Phone,
                    'language' => \App\Modules\Guardians\Domain\GuardianLanguage::English,
                    'emergency_contact_name' => $guardianFirst.' '.$lastName,
                    'emergency_contact_phone' => \App\Modules\Guardians\Domain\PhoneNumber::normalise('6'.random_int(10000000, 99999999)),
                    'emergency_contact_relationship' => 'Parent',
                    'emergency_contact_address' => 'Rue '.($i + 1),
                    'photo_path' => null,
                    'status' => \App\Modules\Guardians\Domain\GuardianStatus::Active,
                    'notify_sms' => true,
                    'notify_email' => false,
                    'notify_push' => false,
                    'receives_reports' => true,
                    'receives_invoices' => true,
                    'portal_user_id' => null,
                    'is_archived' => false,
                ]);

                StudentGuardian::query()->create([
                    'student_id' => $student->id,
                    'guardian_id' => $guardian->id,
                    'relationship' => $g === 0 ? GuardianRelationship::Father : GuardianRelationship::Mother,
                    'relationship_other' => null,
                    'is_primary' => $g === 0,
                    'has_custody' => $g === 0,
                    'receives_reports' => true,
                    'receives_invoices' => true,
                    'is_emergency_contact' => true,
                    'is_authorised_for_pickup' => true,
                    'is_fee_payer' => $g === 0,
                    'valid_from' => '2026-09-01',
                    'valid_to' => null,
                    'revocation_reason' => null,
                    'created_by' => null,
                    'updated_by' => null,
                ]);
            }

            $classGroupId = $classGroupIds[$i % count($classGroupIds)];

            $enrollment = app(EnrollStudent::class)->handle(
                studentId: (int) $student->id,
                academicYearId: (int) $this->academicYearId,
                classGroupId: $classGroupId,
                enrolledOn: '2026-09-05',
            );

            $enrollmentIds[] = (int) $enrollment->id;
        }

        // Remedial pass: any student (e.g. left over from an earlier
        // interrupted run) who still has no enrollment for this academic
        // year gets one now, so re-running the seeder after a partial
        // failure converges to a fully consistent dataset.
        $enrolledStudentIds = DB::table('enrollments')
            ->where('academic_year_id', $this->academicYearId)
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $unenrolled = Student::query()->whereNotIn('id', $enrolledStudentIds)->get();

        foreach ($unenrolled as $index => $student) {
            $classGroupId = $classGroupIds[$index % count($classGroupIds)];

            $enrollment = app(EnrollStudent::class)->handle(
                studentId: (int) $student->id,
                academicYearId: (int) $this->academicYearId,
                classGroupId: $classGroupId,
                enrolledOn: '2026-09-05',
            );

            $enrollmentIds[] = (int) $enrollment->id;
        }

        return $enrollmentIds;
    }

    private function createFeeStructure(): int
    {
        $existing = DB::table('fee_structures')
            ->where('academic_year_id', $this->academicYearId)
            ->where('school_section_id', $this->schoolSectionId)
            ->where('name', 'Standard Fees 2026/2027')
            ->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        $revenueAccountId = DB::table('chart_of_accounts')->where('code', '706')->value('id');

        if (! is_numeric($revenueAccountId)) {
            throw new \RuntimeException('Account 706 is not seeded; run migrations first.');
        }

        $structureId = (int) DB::table('fee_structures')->insertGetId([
            'academic_year_id' => $this->academicYearId,
            'school_section_id' => $this->schoolSectionId,
            'class_level_id' => 0,
            'stream_id' => 0,
            'enrollment_status_scope' => 'any',
            'boarding_scope' => 'any',
            'name' => 'Standard Fees 2026/2027',
            'status' => 'active',
            'version' => 1,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = [
            ['name' => 'Tuition Fee', 'amount' => 150_000],
            ['name' => 'PTA Levy', 'amount' => 10_000],
            ['name' => 'Examination Fee', 'amount' => 15_000],
            ['name' => 'Library Fee', 'amount' => 5_000],
            ['name' => 'Sports Fee', 'amount' => 5_000],
        ];

        foreach ($items as $order => $item) {
            $categoryId = DB::table('fee_categories')->where('name', $item['name'].' category')->value('id');

            if (! is_numeric($categoryId)) {
                $categoryId = DB::table('fee_categories')->insertGetId([
                    'code' => 'CAT'.Str::upper(Str::random(6)),
                    'name' => $item['name'].' category',
                    'name_fr' => $item['name'].' categorie',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $feeItemId = DB::table('fee_items')->where('code', 'DEMO'.Str::upper(Str::slug($item['name'], '')))->value('id');

            if (! is_numeric($feeItemId)) {
                $feeItemId = DB::table('fee_items')->insertGetId([
                    'code' => 'DEMO'.Str::upper(Str::slug($item['name'], '')),
                    'name' => $item['name'],
                    'name_fr' => $item['name'],
                    'fee_category_id' => $categoryId,
                    'collection_basis' => 'own_revenue',
                    'third_party_fund_id' => null,
                    'revenue_account_id' => $revenueAccountId,
                    'recognition_method' => 'on_issue',
                    'tax_code_id' => null,
                    'is_refundable' => false,
                    'is_mandatory' => true,
                    'default_recurrence' => 'per_year',
                    'asset_or_service_note' => null,
                    'is_archived' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('fee_structure_lines')->insert([
                'fee_structure_id' => $structureId,
                'fee_item_id' => $feeItemId,
                'amount' => $item['amount'],
                'term_id' => 0,
                'service_period_start' => null,
                'service_period_end' => null,
                'is_optional' => false,
                'display_order' => $order + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $structureId;
    }

    /**
     * @param  list<int>  $enrollmentIds
     */
    private function generateAndCollectInvoices(int $structureId, array $enrollmentIds): void
    {
        $accountant = $this->demoAccountant();
        $this->ensureInvoiceIssuedPostingRule($accountant);
        $this->ensureCashPaymentPostingRule($accountant);

        $actor = $accountant->toAuditActor();

        // Bill a meaningful subset (60) of the students.
        $subset = array_slice($enrollmentIds, 0, min(60, count($enrollmentIds)));

        $result = app(GenerateInvoices::class)->forEnrollments($subset, [
            'academic_year_id' => $this->academicYearId,
            'fiscal_year_id' => $this->fiscalYearId,
            'term_id' => null,
            'issue_date' => '2026-09-08',
            'due_date' => '2026-10-08',
        ], $actor);

        $created = $result['created'];

        // Converge rather than bail. On a re-run (or after an interrupted
        // run) the invoices already exist, so `created` is empty - but the
        // payments that should hang off them may not have been recorded yet.
        // Returning here would leave the dataset permanently half-built,
        // which is the opposite of the per-section idempotency this seeder
        // documents. Fall back to the already-issued invoices instead.
        $issued = $created !== []
            ? app(IssueInvoice::class)->handle($created, $actor)
            : \App\Modules\Fees\Models\Invoice::query()
                ->where('academic_year_id', $this->academicYearId)
                ->where('status', 'issued')
                ->orderBy('id')
                ->get();

        foreach ($issued as $index => $invoice) {
            // Never double-collect: RecordPayment is idempotent on its key,
            // but skipping early keeps the run fast and the log honest.
            if (DB::table('payments')->where('idempotency_key', 'demo-payment-'.$invoice->id)->exists()) {
                continue;
            }

            $gross = (int) DB::table('invoice_lines')->where('invoice_id', $invoice->id)->sum('amount');

            // Realistic mix: ~1/3 unpaid, ~1/3 partially paid, ~1/3 fully paid.
            $mod = $index % 3;

            if ($mod === 0) {
                continue; // unpaid
            }

            $amountToPay = $mod === 1
                ? (int) round($gross * 0.5)
                : $gross;

            if ($amountToPay <= 0) {
                continue;
            }

            // 02-accounting §1.3: a real school collects across several
            // floats, so the demo does too - otherwise the treasury
            // separation this build exists to prove is invisible on screen.
            // Cash is the bulk, then MTN, Orange, and the occasional bank
            // transfer, matching how Cameroonian school fees actually arrive.
            // 04-fees §2.4: cash needs no reference, but mobile money and
            // bank transfers do - that is the transaction id the float is
            // later reconciled against, and RecordPayment rightly refuses
            // without it.
            [$method, $treasuryCode, $reference] = match ($index % 8) {
                0, 1, 2 => [PaymentMethod::Cash, '571', null],
                3, 4 => [PaymentMethod::MobileMoney, '5521', sprintf('MM-MTN-%06d', $invoice->id)],
                5, 6 => [PaymentMethod::MobileMoney, '5522', sprintf('MM-OM-%06d', $invoice->id)],
                default => [PaymentMethod::Bank, '521', sprintf('TRF-%06d', $invoice->id)],
            };

            $treasuryAccountId = DB::table('chart_of_accounts')
                ->where('code', $treasuryCode)->value('id');

            app(RecordPayment::class)->handle(
                studentId: $invoice->student_id,
                academicYearId: $this->academicYearId,
                fiscalYearId: $this->fiscalYearId,
                method: $method,
                amount: Money::of($amountToPay),
                payerName: 'Parent of student '.$invoice->student_id,
                valueDate: '2026-10-01',
                actor: $actor,
                feeAmount: Money::zero(),
                feeBearer: FeeBearer::None,
                reference: $reference,
                payerPhone: null,
                enrollmentId: $invoice->enrollment_id,
                targets: null,
                idempotencyKey: 'demo-payment-'.$invoice->id,
                notes: 'Demo data payment',
                treasuryAccountId: is_numeric($treasuryAccountId) ? (int) $treasuryAccountId : null,
            );
        }
    }

    private function demoAdmin(): \App\Modules\Identity\Models\User
    {
        (new RolePermissionSeeder())->run();

        $user = \App\Modules\Identity\Models\User::query()->firstOrCreate(
            ['email' => 'demo.admin@opeschool.test'],
            ['name' => 'Demo Admin', 'password' => Str::random(32)],
        );

        if (! $user->hasRole(\App\Modules\Identity\Domain\Role::SuperAdmin->value)) {
            $user->assignRole(\App\Modules\Identity\Domain\Role::SuperAdmin->value);
        }

        return $user->fresh() ?? $user;
    }

    private function demoAccountant(): \App\Modules\Identity\Models\User
    {
        (new RolePermissionSeeder())->run();

        $user = \App\Modules\Identity\Models\User::query()->firstOrCreate(
            ['email' => 'demo.bursar@opeschool.test'],
            ['name' => 'Demo Bursar', 'password' => Str::random(32)],
        );

        if (! $user->hasRole(\App\Modules\Identity\Domain\Role::Bursar->value)) {
            $user->assignRole(\App\Modules\Identity\Domain\Role::Bursar->value);
        }

        if (! $user->hasRole(\App\Modules\Identity\Domain\Role::Accountant->value)) {
            $user->assignRole(\App\Modules\Identity\Domain\Role::Accountant->value);
        }

        return $user->fresh() ?? $user;
    }

    private function ensureInvoiceIssuedPostingRule(\App\Modules\Identity\Models\User $accountant): void
    {
        $exists = DB::table('posting_rules')->where('code', 'demo_invoice_issued')->exists();

        if ($exists) {
            return;
        }

        $journalId = DB::table('journals')->where('code', 'VE')->value('id');

        app(\App\Modules\Accounting\Actions\SavePostingRule::class)->handle([
            'code' => 'demo_invoice_issued',
            'event' => \App\Modules\Accounting\Domain\PostingEvent::FeeInvoiceIssued->value,
            'journal_id' => (int) $journalId,
            'label_expression' => 'Facture {invoice.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => \App\Modules\Accounting\Domain\AccountSource::PayloadPath,
                'account_path' => 'invoice.receivable_account_id',
                'sign' => \App\Modules\Accounting\Domain\LineSign::Debit,
                'amount_expression' => 'invoice.total',
                'partner_source' => 'invoice.partner',
                'label_expression' => 'Client - {invoice.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => \App\Modules\Accounting\Domain\AccountSource::PayloadPath,
                'account_path' => 'item.revenue_account_id',
                'iterates_over' => 'invoice.lines',
                'sign' => \App\Modules\Accounting\Domain\LineSign::Credit,
                'amount_expression' => 'item.amount',
                'label_expression' => '{item.label}',
            ],
        ], $accountant->toAuditActor());
    }

    private function ensureCashPaymentPostingRule(\App\Modules\Identity\Models\User $accountant): void
    {
        $exists = DB::table('posting_rules')->where('code', 'demo_cash_payment')->exists();

        if ($exists) {
            return;
        }

        $journalId = DB::table('journals')->where('code', 'VE')->value('id');

        app(\App\Modules\Accounting\Actions\SavePostingRule::class)->handle([
            'code' => 'demo_cash_payment',
            'event' => \App\Modules\Accounting\Domain\PostingEvent::FeePaymentReceived->value,
            'journal_id' => (int) $journalId,
            'label_expression' => 'Encaissement especes {payment.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ], [
            [
                // 02-accounting §1.3/§7: debit the treasury account the money
                // ACTUALLY landed in (cash box / bank / MTN / Orange), read
                // from the payment payload - never a hardcoded 57. Hardcoding
                // it is the v1 defect that made "cash in hand" silently
                // absorb bank transfers and e-money, and it breaks outright
                // once 57 becomes a non-postable parent of 571.
                'sequence' => 1,
                'account_source' => \App\Modules\Accounting\Domain\AccountSource::PayloadPath,
                'account_path' => 'payment.method.treasury_account_id',
                'sign' => \App\Modules\Accounting\Domain\LineSign::Debit,
                'amount_expression' => 'payment.amount',
                'label_expression' => 'Encaissement {payment.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => \App\Modules\Accounting\Domain\AccountSource::Literal,
                'account_code' => '4111',
                'sign' => \App\Modules\Accounting\Domain\LineSign::Credit,
                'amount_expression' => 'payment.amount',
                'is_balancing' => true,
                'partner_source' => 'payment.partner',
                'label_expression' => '{payment.partner_label} - {payment.reference}',
            ],
        ], $accountant->toAuditActor());
    }
}
