<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\HR\Domain\CnpsRegistrationStatus;
use App\Modules\HR\Domain\ContractType;
use App\Modules\HR\Domain\SocialSecurityStatus;
use App\Modules\HR\Domain\WorkingTime;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grows Heritage College staff from 18 to 50+ with realistic roles, subject/
 * class assignments for teachers, and payroll/CNPS data.
 *
 * Idempotency strategy matches DemoDataSeeder: query-before-insert guards at
 * every insertion point, no single wrapping transaction, safe to re-run.
 *
 * NOTE on roles: the Spatie role list (docs/specs) has no dedicated
 * "boarding master", "guidance/counselling", "ICT staff" or "security" role.
 * welfare_officer is reused for guidance/counselling and boarding-type staff;
 * ICT/lab/support/security staff get a plain position title with no special
 * portal role (front_desk or store_keeper where plausible, otherwise none).
 */
final class ExpandStaffSeeder extends Seeder
{
    /** @var list<string> */
    private array $maleFirstNames = [
        'Jean', 'Paul', 'Emmanuel', 'Achille', 'Bertrand', 'Ferdinand', 'Serge',
        'Yves', 'Patrick', 'Aristide', 'Boris', 'Cedric', 'Landry', 'Herve',
        'Blaise', 'Arnaud', 'Junior', 'Frank', 'Innocent', 'Desire', 'Romeo',
        'Christian', 'Alain', 'Willy', 'Steve', 'Eric',
    ];

    /** @var list<string> */
    private array $femaleFirstNames = [
        'Marie', 'Grace', 'Aminatou', 'Solange', 'Carine', 'Josiane', 'Nadege',
        'Larissa', 'Odette', 'Chantal', 'Reine', 'Aurelie', 'Pulcherie',
        'Delphine', 'Estelle', 'Vanessa', 'Brenda', 'Sandrine', 'Clarisse',
        'Beatrice', 'Ornella', 'Prudence', 'Divine', 'Merveille',
    ];

    /** @var list<string> */
    private array $lastNames = [
        'Ngwa', 'Fotso', 'Mballa', 'Njoya', 'Atangana', 'Kamga', 'Tchoumi',
        'Biya', 'Ndongo', 'Fokou', 'Talla', 'Etoundi', 'Mvondo', 'Nkeng',
        'Wamba', 'Sonkeng', 'Ekwalla', 'Bassong', 'Nguemo', 'Djoumessi',
        'Awa', 'Che', 'Fru', 'Manga', 'Ondoua', 'Simo', 'Tabi', 'Yong',
    ];

    /**
     * Each row: [role_key, position_code, position_name, department_code,
     * spatie_role|null, salary_band ('senior'|'mid'|'junior'), is_teacher].
     *
     * @var list<array{0:string,1:string,2:string,3:string,4:?string,5:string,6:bool}>
     */
    private array $roleCatalogue = [
        ['vice_principal', 'VP', 'Vice Principal', 'ADM', 'vice_principal', 'senior', false],
        ['coordinator_1', 'ACO', 'Academic Coordinator', 'ACD', 'discipline_master', 'senior', false],
        ['coordinator_2', 'DCO', 'Discipline Coordinator', 'ACD', 'discipline_master', 'senior', false],
        ['bursar_2', 'BUR2', 'Deputy Bursar', 'BUR', 'bursar', 'senior', false],
        ['accountant_1', 'ACC1', 'Accountant', 'BUR', 'accountant', 'mid', false],
        ['accountant_2', 'ACC2', 'Accountant', 'BUR', 'accountant', 'mid', false],
        ['accountant_3', 'ACC3', 'Accountant', 'BUR', 'accountant', 'junior', false],
        ['registrar', 'REG', 'Registrar', 'ACD', 'registrar', 'mid', false],
        ['hr_officer', 'HRO', 'HR / Admin Officer', 'HR', 'hr_officer', 'mid', false],
        ['payroll_officer', 'PRO', 'Payroll Officer', 'HR', 'payroll_officer', 'mid', false],
        ['front_desk_1', 'FD1', 'Front Desk / Secretary', 'ADM', 'front_desk', 'junior', false],
        ['front_desk_2', 'FD2', 'Front Desk / Secretary', 'ADM', 'front_desk', 'junior', false],
        ['exams_officer', 'EXO', 'Examinations Officer', 'ACD', 'exams_officer', 'mid', false],
        ['librarian', 'LIB', 'Librarian', 'LIB', 'librarian', 'junior', false],
        ['nurse', 'NUR', 'School Nurse', 'HLT', 'nurse', 'mid', false],
        ['welfare_1', 'WEL1', 'Guidance & Counselling Officer', 'WEL', 'welfare_officer', 'mid', false],
        ['welfare_2', 'WEL2', 'Boarding / Welfare Officer', 'WEL', 'welfare_officer', 'junior', false],
        ['store_keeper', 'STK', 'Store Keeper', 'BUR', 'store_keeper', 'junior', false],
        ['ict_support', 'ICT', 'ICT / Lab Support Technician', 'ICT', null, 'junior', false],
        ['security_1', 'SEC1', 'Security Guard', 'ADM', null, 'junior', false],
        ['security_2', 'SEC2', 'Security Guard', 'ADM', null, 'junior', false],
        ['support_1', 'SUP1', 'General Support Staff', 'ADM', null, 'junior', false],
    ];

    private ?int $employerProfileId = null;

    private ?int $demoAdminUserId = null;

    private function demoAdminUserId(): int
    {
        if ($this->demoAdminUserId !== null) {
            return $this->demoAdminUserId;
        }

        $id = DB::table('users')->where('email', 'demo.admin@opeschool.test')->value('id');

        if (! is_numeric($id)) {
            (new RolePermissionSeeder)->run();

            $id = User::query()->firstOrCreate(
                ['email' => 'demo.admin@opeschool.test'],
                ['name' => 'Demo Admin', 'password' => Str::random(32)],
            )->id;
        }

        $this->demoAdminUserId = (int) $id;

        return $this->demoAdminUserId;
    }

    public function run(): void
    {
        $departmentIds = $this->createDepartments();
        $positionIds = $this->createPositions($departmentIds);
        $salaryGradeIds = $this->createSalaryGrades();

        $classLevelIds = DB::table('class_levels')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $subjectIds = DB::table('subjects')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $classGroups = DB::table('class_groups')->select('id', 'class_level_id')->get();

        // Target sequence range is fixed at STF-2026-019..052 (34 new staff
        // on top of the original 18), NOT derived from the current row
        // count - a partial prior run may already have inserted some of
        // these rows without yet reaching the contract/pay steps below, and
        // deriving from count() would then skip them on re-run.
        $newStaffIds = $this->createStaff(34);
        $this->assignContractsAndPay($newStaffIds, $departmentIds, $positionIds, $salaryGradeIds);
        $this->backfillCnpsForExistingStaff();
        $this->assignTeachersToSubjectsAndClasses($newStaffIds, $classLevelIds, $subjectIds, $classGroups);

        $this->command?->info(sprintf(
            'ExpandStaffSeeder complete: %d staff total (%d newly created).',
            DB::table('staff_members')->count(),
            count($newStaffIds),
        ));
    }

    /**
     * @return array<string, int> code => id
     */
    private function createDepartments(): array
    {
        $departments = [
            ['code' => 'ADM', 'name' => 'Administration'],
            ['code' => 'ACD', 'name' => 'Academics'],
            ['code' => 'BUR', 'name' => 'Bursary'],
            ['code' => 'HR', 'name' => 'Human Resources'],
            ['code' => 'LIB', 'name' => 'Library'],
            ['code' => 'HLT', 'name' => 'Health'],
            ['code' => 'WEL', 'name' => 'Welfare & Boarding'],
            ['code' => 'ICT', 'name' => 'ICT & Support Services'],
        ];

        $ids = [];

        foreach ($departments as $dept) {
            $id = DB::table('departments')->where('code', $dept['code'])->value('id');

            if (! is_numeric($id)) {
                $id = DB::table('departments')->insertGetId([
                    'code' => $dept['code'],
                    'name' => $dept['name'],
                    'name_fr' => $dept['name'],
                    'head_staff_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[$dept['code']] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $departmentIds
     * @return array<string, int> position code => id
     */
    private function createPositions(array $departmentIds): array
    {
        $positions = [
            ['code' => 'PRIN', 'name' => 'Principal', 'is_teaching' => false],
            ['code' => 'TCHR', 'name' => 'Teacher', 'is_teaching' => true],
        ];

        foreach ($this->roleCatalogue as $row) {
            $positions[] = ['code' => $row[1], 'name' => $row[2], 'is_teaching' => false];
        }

        $ids = [];

        foreach ($positions as $position) {
            $id = DB::table('positions')->where('code', $position['code'])->value('id');

            if (! is_numeric($id)) {
                $id = DB::table('positions')->insertGetId([
                    'code' => $position['code'],
                    'name' => $position['name'],
                    'name_fr' => $position['name'],
                    'is_teaching' => $position['is_teaching'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[$position['code']] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return array<string, int> band => salary_grade id
     */
    private function createSalaryGrades(): array
    {
        $grades = [
            'senior' => ['code' => 'SG-SEN', 'name' => 'Senior Grade', 'category' => 'senior', 'echelon' => 'A'],
            'mid' => ['code' => 'SG-MID', 'name' => 'Mid Grade', 'category' => 'mid', 'echelon' => 'B'],
            'junior' => ['code' => 'SG-JUN', 'name' => 'Junior Grade', 'category' => 'junior', 'echelon' => 'C'],
        ];

        $ids = [];

        foreach ($grades as $band => $grade) {
            $id = DB::table('salary_grades')->where('code', $grade['code'])->value('id');

            if (! is_numeric($id)) {
                $id = DB::table('salary_grades')->insertGetId([
                    'code' => $grade['code'],
                    'name' => $grade['name'],
                    'name_fr' => $grade['name'],
                    'category' => $grade['category'],
                    'echelon' => $grade['echelon'],
                    'collective_agreement_id' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[$band] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return list<int> newly created (or already-present matching sequence) staff ids
     */
    private function createStaff(int $toCreate): array
    {
        $ids = [];

        // Fixed base of 18: the original DemoDataSeeder staff count
        // (STF-2026-001..018). Deriving the base from the current max
        // staff_no would double-count on a re-run that already inserted
        // some of these rows in an earlier, interrupted execution.
        $baseSeq = 18;

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $baseSeq + $i + 1;
            $staffNo = sprintf('STF-2026-%03d', $seq);

            $id = DB::table('staff_members')->where('staff_no', $staffNo)->value('id');

            if (is_numeric($id)) {
                $ids[] = (int) $id;

                continue;
            }

            $gender = $seq % 2 === 0 ? 'male' : 'female';
            $firstName = $gender === 'male'
                ? $this->maleFirstNames[$seq % count($this->maleFirstNames)]
                : $this->femaleFirstNames[$seq % count($this->femaleFirstNames)];
            $lastName = $this->lastNames[$seq % count($this->lastNames)];

            $id = DB::table('staff_members')->insertGetId([
                'staff_no' => $staffNo,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'other_names' => null,
                'gender' => $gender,
                'date_of_birth' => sprintf('19%d-0%d-1%d', 65 + ($seq % 30), 1 + ($seq % 9), $seq % 8),
                'place_of_birth' => 'Bamenda',
                'nationality' => 'CM',
                'phone' => sprintf('+237 6%08d', 71000000 + $seq),
                'email' => null,
                'photo_path' => null,
                'status' => 'active',
                'is_archived' => false,
                'cnps_registration_status' => 'pending',
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $staffIds
     * @param  array<string, int>  $departmentIds
     * @param  array<string, int>  $positionIds
     * @param  array<string, int>  $salaryGradeIds
     */
    private function assignContractsAndPay(
        array $staffIds,
        array $departmentIds,
        array $positionIds,
        array $salaryGradeIds,
    ): void {
        // Deterministic role assignment: index 0 is Principal, index 1 the
        // catalogue roles in order, remainder are teachers.
        $catalogueCount = count($this->roleCatalogue);

        foreach ($staffIds as $index => $staffId) {
            $existingContractId = DB::table('staff_contracts')->where('staff_member_id', $staffId)->value('id');

            if (is_numeric($existingContractId)) {
                // A contract already exists (e.g. from an earlier run that
                // failed after the contract insert but before compensation/
                // CNPS were written). Converge rather than skip: top up
                // whatever this staff member is still missing.
                $this->ensureCompensationAndCnps(
                    StaffMember::query()->findOrFail($staffId),
                    (int) $existingContractId,
                );

                continue;
            }

            $staff = StaffMember::query()->findOrFail($staffId);

            $yearsAgo = 8 - ($index % 8); // tenure spread 0-8 years, deterministic per staff
            $startsOn = now()->copy()->subYears($yearsAgo)->subDays($index % 28)->startOfMonth()->addDays($index % 5)->toDateString();

            if ($index === 0) {
                // Only one Principal expected among new hires if none already
                // seeded; the 18 existing demo staff carry no contracts, so
                // this is the school's sole Principal contract.
                $exists = DB::table('staff_contracts')->where('contract_role', 'PRIN')->exists();

                if (! $exists) {
                    [$positionCode, $deptCode, $band] = ['PRIN', 'ADM', 'senior'];
                    $this->openContractAndPay($staff, $positionCode, $deptCode, $band, $departmentIds, $positionIds, $salaryGradeIds, $startsOn, $index);

                    continue;
                }
            }

            $catalogueIndex = $index - 1;

            if ($catalogueIndex >= 0 && $catalogueIndex < $catalogueCount) {
                $row = $this->roleCatalogue[$catalogueIndex];
                [, $positionCode, , $deptCode, , $band] = $row;
                $this->openContractAndPay($staff, $positionCode, $deptCode, $band, $departmentIds, $positionIds, $salaryGradeIds, $startsOn, $index);

                continue;
            }

            // Everyone past the catalogue is a Teacher.
            $band = match (true) {
                $yearsAgo >= 6 => 'senior',
                $yearsAgo >= 3 => 'mid',
                default => 'junior',
            };

            $this->openContractAndPay($staff, 'TCHR', 'ACD', $band, $departmentIds, $positionIds, $salaryGradeIds, $startsOn, $index);
        }
    }

    /**
     * @param  array<string, int>  $departmentIds
     * @param  array<string, int>  $positionIds
     * @param  array<string, int>  $salaryGradeIds
     */
    private function openContractAndPay(
        StaffMember $staff,
        string $positionCode,
        string $deptCode,
        string $band,
        array $departmentIds,
        array $positionIds,
        array $salaryGradeIds,
        string $startsOn,
        int $index,
    ): void {
        $contractId = DB::table('staff_contracts')->insertGetId([
            'staff_member_id' => $staff->id,
            'contract_role' => $positionCode,
            'contract_type' => ContractType::Cdi->value,
            'working_time' => WorkingTime::FullTime->value,
            'department_id' => $departmentIds[$deptCode],
            'position_id' => $positionIds[$positionCode],
            'salary_grade_id' => $salaryGradeIds[$band],
            'collective_agreement_id' => null,
            'category' => ucfirst($band),
            'echelon' => match ($band) {
                'senior' => 'A',
                'mid' => 'B',
                default => 'C',
            },
            'starts_on' => $startsOn,
            'ends_on' => null,
            'probation_end' => null,
            'renewal_count' => 0,
            'renewed_from_contract_id' => null,
            'converted_to_cdi_on' => null,
            'mintss_visa_ref' => null,
            'social_security_status' => SocialSecurityStatus::AffilieCnps->value,
            'is_payroll_eligible' => true,
            'rp_risk_class_override' => null,
            'override_reason' => null,
            'seniority_reference_date' => $startsOn,
            'termination_reason' => null,
            // active_role_key is a DB-generated column; must not be set.
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ensureCompensationAndCnps($staff, $contractId, $band, $startsOn);
    }

    /**
     * Idempotently ensures a BASIC salary line and CNPS registration exist
     * for a staff member's contract - shared by the fresh-hire path and the
     * remedial top-up path for a contract left over from an interrupted run.
     */
    private function ensureCompensationAndCnps(
        StaffMember $staff,
        int $contractId,
        ?string $band = null,
        ?string $startsOn = null,
    ): void {
        $startsOn ??= (string) DB::table('staff_contracts')->where('id', $contractId)->value('starts_on');
        $band ??= (string) DB::table('staff_contracts')->where('id', $contractId)->value('category');
        $band = strtolower($band);

        // Base salary 250,000-400,000 FCFA, correlated with band, with a few
        // thousand FCFA of natural per-person noise rather than a flat tier.
        [$floor, $ceiling] = match ($band) {
            'senior' => [350_000, 400_000],
            'mid' => [300_000, 350_000],
            default => [250_000, 300_000],
        };

        // Deterministic noise from the staff id so re-runs are stable.
        $noise = ($staff->id * 137) % ($ceiling - $floor + 1);
        $basicSalary = $floor + $noise;

        $hasCompensation = DB::table('staff_compensations')
            ->where('staff_contract_id', $contractId)
            ->where('component_code', 'BASIC')
            ->exists();

        if (! $hasCompensation) {
            DB::table('staff_compensations')->insert([
                'staff_contract_id' => $contractId,
                'component_code' => 'BASIC',
                'amount' => $basicSalary,
                'rate_bp' => null,
                'effective_from' => $startsOn,
                'effective_to' => null,
                'retroactive_from' => null,
                'granted_by' => $this->demoAdminUserId(),
                'grant_reason' => 'Initial demo salary grant',
                'document_id' => null,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // CNPS: plausible number + registered status for every new staff
        // member. cnps_number is encrypted at rest on the model, so it must
        // be set through Eloquent (blind index computed on save), never a
        // raw DB::table insert.
        if ($staff->cnps_number === null) {
            $cnpsNumber = sprintf('CNPS-%09d', 100000000 + $staff->id);
            $staff->cnps_number = $cnpsNumber;
            $staff->cnps_number_blind_index = StaffMember::blindIndexFor($cnpsNumber);
            $staff->cnps_registration_status = CnpsRegistrationStatus::Registered;
            $staff->cnps_registered_on = $startsOn;
            $staff->cnps_registration_deadline = null;
            $staff->save();
        }
    }

    /**
     * Backfill CNPS number/status for the 18 pre-existing demo staff where
     * it is a clear gap (status not_required with no number) - only an
     * additive, idempotent update, never touching staff that already carry
     * a real status.
     */
    private function backfillCnpsForExistingStaff(): void
    {
        $gaps = StaffMember::query()
            ->where('cnps_registration_status', CnpsRegistrationStatus::NotRequired->value)
            ->whereNull('cnps_number')
            ->get();

        foreach ($gaps as $staff) {
            $cnpsNumber = sprintf('CNPS-%09d', 100000000 + $staff->id);
            $staff->cnps_number = $cnpsNumber;
            $staff->cnps_number_blind_index = StaffMember::blindIndexFor($cnpsNumber);
            $staff->cnps_registration_status = CnpsRegistrationStatus::Registered;
            $staff->cnps_registered_on = now()->toDateString();
            $staff->cnps_registration_deadline = null;
            $staff->save();
        }
    }

    /**
     * @param  list<int>  $staffIds
     * @param  list<int>  $classLevelIds
     * @param  list<int>  $subjectIds
     * @param  Collection<int, object{id:int,class_level_id:int}>  $classGroups
     */
    private function assignTeachersToSubjectsAndClasses(
        array $staffIds,
        array $classLevelIds,
        array $subjectIds,
        Collection $classGroups,
    ): void {
        $catalogueCount = count($this->roleCatalogue);
        $teacherIds = [];

        foreach ($staffIds as $index => $staffId) {
            $catalogueIndex = $index - 1;
            $isTeacher = $index !== 0 && ! ($catalogueIndex >= 0 && $catalogueIndex < $catalogueCount);

            if ($isTeacher) {
                $teacherIds[] = $staffId;
            }
        }

        if ($teacherIds === [] || $classLevelIds === [] || $subjectIds === []) {
            return;
        }

        $academicYearId = (int) DB::table('academic_years')->where('is_current', true)->value('id');

        foreach ($teacherIds as $tIndex => $staffId) {
            // Each new teacher takes 1-2 subjects across 1-2 class levels,
            // mirroring the existing seeder's per-level subject_allocations
            // rows (subject_allocations is level-wide, not per-teacher, so
            // the teacher's ownership is recorded via class_groups where a
            // slot is free, plus its subject_allocations existence check).
            $subjectId = $subjectIds[$tIndex % count($subjectIds)];
            $classLevelId = $classLevelIds[$tIndex % count($classLevelIds)];

            $allocationExists = DB::table('subject_allocations')
                ->where('academic_year_id', $academicYearId)
                ->where('class_level_id', $classLevelId)
                ->where('stream_id', 0)
                ->where('subject_id', $subjectId)
                ->exists();

            if (! $allocationExists) {
                DB::table('subject_allocations')->insert([
                    'academic_year_id' => $academicYearId,
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

            // Slot this teacher in as class_teacher_staff_id for one class
            // group at that level, if a group there still has no teacher.
            $freeGroup = $classGroups
                ->where('class_level_id', $classLevelId)
                ->first(function ($group) {
                    $current = DB::table('class_groups')->where('id', $group->id)->value('class_teacher_staff_id');

                    return $current === null;
                });

            if ($freeGroup !== null) {
                DB::table('class_groups')->where('id', $freeGroup->id)->update([
                    'class_teacher_staff_id' => $staffId,
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
