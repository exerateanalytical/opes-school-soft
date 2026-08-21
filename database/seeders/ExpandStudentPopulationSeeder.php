<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Guardians\Domain\Gender;
use App\Modules\Guardians\Domain\GuardianIdType;
use App\Modules\Guardians\Domain\GuardianLanguage;
use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Guardians\Domain\GuardianStatus;
use App\Modules\Guardians\Domain\MaritalStatus;
use App\Modules\Guardians\Domain\PhoneNumber;
use App\Modules\Guardians\Domain\PreferredContactMethod;
use App\Modules\Guardians\Domain\ResidentialStatus;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Actions\EnrollStudent;
use App\Modules\Students\Domain\Gender as StudentGender;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Grows the demo student population from DemoDataSeeder's ~100 baseline up to
 * ~900, reusing EnrollStudent (business logic: capacity locking, segments,
 * status derivation) rather than raw inserts. Idempotent/resumable: it
 * compares Student::count() (and per-level counts) against targets and only
 * tops up the gap, so re-running after a partial/interrupted run converges
 * instead of duplicating. Safe to run alongside sibling agents that are
 * adding class levels/academic years/staff concurrently - it re-queries
 * class_levels/class_groups at the start of run() rather than caching them.
 */
final class ExpandStudentPopulationSeeder extends Seeder
{
    private const TARGET_TOTAL = 900;

    private const MIN_PER_STREAM = 30;

    private const MAX_PER_STREAM = 45;

    private const CLASS_GROUP_CAPACITY = 60;

    /** @var list<string> */
    private array $maleFirstNames = [
        'Jean', 'Paul', 'Emmanuel', 'Achille', 'Bertrand', 'Ferdinand', 'Serge',
        'Yves', 'Patrick', 'Aristide', 'Boris', 'Cedric', 'Landry', 'Herve',
        'Blaise', 'Arnaud', 'Junior', 'Frank', 'Christian', 'Rodrigue',
        'Steve', 'Alain', 'Franck', 'Hilaire', 'Innocent', 'Ivan', 'Kevin',
        'Leonel', 'Marcel', 'Maxime', 'Nestor', 'Norbert', 'Pacome', 'Prince',
        'Romuald', 'Samuel', 'Thierry', 'Valery', 'Wilfried', 'Xavier',
        'Brice', 'Cyrille', 'Desire', 'Elvis', 'Fabrice', 'Gaston', 'Hugues',
        'Ismael', 'Joel', 'Kelvin',
    ];

    /** @var list<string> */
    private array $femaleFirstNames = [
        'Marie', 'Grace', 'Aminatou', 'Solange', 'Carine', 'Josiane', 'Nadege',
        'Larissa', 'Odette', 'Chantal', 'Reine', 'Aurelie', 'Pulcherie',
        'Delphine', 'Estelle', 'Vanessa', 'Brenda', 'Sandrine', 'Rachel',
        'Divine', 'Precious', 'Merveille', 'Gwladys', 'Huguette', 'Ines',
        'Joyce', 'Kelly', 'Linda', 'Melanie', 'Natacha', 'Olive', 'Priscille',
        'Queenta', 'Ruth', 'Stephanie', 'Tatiana', 'Ursule', 'Viviane',
        'Whitney', 'Yvana', 'Aline', 'Bertille', 'Clarisse', 'Doriane',
        'Edwige', 'Flore', 'Gaelle', 'Hortense', 'Ida', 'Jessica',
    ];

    /** @var list<string> */
    private array $lastNames = [
        'Ngwa', 'Fotso', 'Mballa', 'Njoya', 'Atangana', 'Kamga', 'Tchoumi',
        'Biya', 'Ndongo', 'Fokou', 'Talla', 'Etoundi', 'Mvondo', 'Nkeng',
        'Wamba', 'Sonkeng', 'Ekwalla', 'Bassong', 'Nguemo', 'Djoumessi',
        'Manga', 'Essomba', 'Fouda', 'Belinga', 'Owona', 'Abanda', 'Nguini',
        'Tabi', 'Achu', 'Ndille', 'Epee', 'Din', 'Moukouri', 'Ngassa',
        'Kenmogne', 'Tagne', 'Tankeu', 'Simo', 'Kwedi', 'Njie', 'Mbappe',
        'Ondoa', 'Zang', 'Ateba', 'Bikoi', 'Ebode', 'Kotto', 'Mbarga',
        'Nlend', 'Tchana',
    ];

    /** @var list<string> */
    private array $doualaNeighbourhoods = [
        'Bonapriso', 'Bonanjo', 'Akwa', 'Deido', 'Bali', 'Makepe', 'Ndogbong',
        'Bonamoussadi', 'Kotto', 'Logbaba', 'New Bell', 'Bepanda', 'Ndokoti',
        'Bonaberi', 'Yassa', 'Bonduma',
    ];

    /** @var list<string> */
    private array $streetNames = [
        'Rue Njo-Njo', 'Rue de la Joie', 'Avenue Charles de Gaulle',
        'Rue Franceville', 'Boulevard de la Liberte', 'Rue des Manguiers',
        'Rue Koloko', 'Avenue du General Leclerc', 'Rue des Cocotiers',
        'Rue Ndogpassi', 'Boulevard de l\'Ocean', 'Rue Toyota', 'Rue Nachtigal',
        'Rue de la Reunification', 'Rue Ivan', 'Rue des Palmiers',
    ];

    /** @var list<string> */
    private array $occupations = [
        'Trader', 'Civil Servant', 'Teacher', 'Nurse', 'Engineer', 'Mechanic',
        'Tailor', 'Business Owner', 'Driver', 'Accountant', 'Pastor',
        'Farmer', 'Electrician', 'Banker', 'Pharmacist', 'Police Officer',
    ];

    private ?int $academicYearId = null;

    /** @var array<int, Guardian[]> family pool keyed by index, storing created Guardian models to reuse for siblings */
    private array $familyPool = [];

    public function run(): void
    {
        $admin = $this->demoAdmin();
        Auth::login($admin);

        $academicYear = DB::table('academic_years')->where('is_current', true)->first();

        if ($academicYear === null) {
            $this->command?->warn('No current academic year found; aborting ExpandStudentPopulationSeeder.');

            return;
        }

        $this->academicYearId = (int) $academicYear->id;

        // Re-query fresh every run: sibling agents may add class levels
        // (e.g. Upper Sixth) or academic years concurrently.
        $classLevels = DB::table('class_levels')
            ->orderBy('order_index')
            ->get();

        if ($classLevels->isEmpty()) {
            $this->command?->warn('No class levels found; aborting ExpandStudentPopulationSeeder.');

            return;
        }

        $staffIds = DB::table('staff_members')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($staffIds === []) {
            $this->command?->warn('No staff members found; cannot create class groups. Aborting.');

            return;
        }

        $levelCount = $classLevels->count();
        $targets = $this->computeLevelTargets($classLevels, $levelCount);

        $totalCreated = 0;

        foreach ($classLevels as $rank => $level) {
            $levelId = (int) $level->id;
            $targetForLevel = $targets[$levelId];

            $streamIds = $this->ensureStreamsForLevel($levelId, (string) $level->name, $targetForLevel, $staffIds);

            $currentForLevel = DB::table('enrollments')
                ->where('academic_year_id', $this->academicYearId)
                ->where('class_level_id', $levelId)
                ->count();

            $toCreate = max(0, $targetForLevel - $currentForLevel);

            if ($toCreate === 0) {
                continue;
            }

            $ageRange = $this->ageRangeForRank($rank, $levelCount);

            $created = $this->createStudentsForLevel($levelId, $streamIds, $toCreate, $ageRange);
            $totalCreated += $created;

            $this->command?->info(sprintf(
                'Level %s: target=%d existing=%d created=%d',
                (string) $level->name,
                $targetForLevel,
                $currentForLevel,
                $created,
            ));
        }

        $finalCount = Student::query()->count();
        $this->command?->info("ExpandStudentPopulationSeeder complete. Total students now: {$finalCount} (created {$totalCreated} this run).");
    }

    /**
     * @param  Collection<int, object>  $classLevels
     * @return array<int, int> class_level_id => target student count
     */
    private function computeLevelTargets(Collection $classLevels, int $levelCount): array
    {
        // Realistic taper: Form1 heaviest, thinning toward the terminal
        // classes as natural attrition (transfers, dropout, repetition
        // exits) removes students each year.
        $weights = [];

        foreach ($classLevels as $rank => $level) {
            $t = $levelCount > 1 ? $rank / ($levelCount - 1) : 0.0;
            // 1.7 at rank 0 down to 0.55 at the last rank.
            $weights[(int) $level->id] = 1.7 - ($t * 1.15);
        }

        $weightSum = array_sum($weights);
        $targets = [];

        foreach ($weights as $levelId => $weight) {
            $targets[$levelId] = (int) round(self::TARGET_TOTAL * $weight / $weightSum);
        }

        return $targets;
    }

    /**
     * @return array{0: int, 1: int} [minAge, maxAge]
     */
    private function ageRangeForRank(int $rank, int $levelCount): array
    {
        // Form1 (rank 0) starts at 11; each subsequent level adds ~1 year,
        // topping out around 18-19 for the terminal class(es).
        $minAge = 11 + $rank;
        $maxAge = min(19, $minAge + 1);

        return [$minAge, $maxAge];
    }

    /**
     * Ensures enough streams exist under a level to keep per-stream
     * population within MIN/MAX_PER_STREAM, adding new class_groups
     * following DemoDataSeeder's naming pattern when needed. Re-checks
     * existing stream names immediately before insert to avoid colliding
     * with a concurrent structure agent.
     *
     * @param  list<int>  $staffIds
     * @return list<int> class_group ids for this level
     */
    private function ensureStreamsForLevel(int $levelId, string $levelName, int $targetForLevel, array $staffIds): array
    {
        $existing = DB::table('class_groups')
            ->where('academic_year_id', $this->academicYearId)
            ->where('class_level_id', $levelId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $desiredStreamCount = max(1, (int) ceil($targetForLevel / self::MAX_PER_STREAM));
        // Don't let per-stream drop below MIN_PER_STREAM by over-splitting.
        $desiredStreamCount = min($desiredStreamCount, max(1, (int) floor($targetForLevel / self::MIN_PER_STREAM)) ?: $desiredStreamCount);

        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $ids = $existing->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $existingLetters = $existing->pluck('name')
            ->map(fn ($n): string => trim((string) Str::afterLast((string) $n, ' ')))
            ->all();

        $staffCursor = count($ids);

        foreach ($letters as $letter) {
            if (count($ids) >= $desiredStreamCount) {
                break;
            }

            if (in_array($letter, $existingLetters, true)) {
                continue;
            }

            $groupName = $levelName.' '.$letter;

            // Re-check immediately before insert: another agent may be
            // creating streams concurrently.
            $collision = DB::table('class_groups')
                ->where('academic_year_id', $this->academicYearId)
                ->where('class_level_id', $levelId)
                ->where('name', $groupName)
                ->value('id');

            if (is_numeric($collision)) {
                $ids[] = (int) $collision;
                $existingLetters[] = $letter;

                continue;
            }

            $teacherId = $staffIds[$staffCursor % count($staffIds)];
            $staffCursor++;

            try {
                $newId = DB::table('class_groups')->insertGetId([
                    'class_level_id' => $levelId,
                    'stream_id' => null,
                    'academic_year_id' => $this->academicYearId,
                    'name' => $groupName,
                    'class_teacher_staff_id' => $teacherId,
                    'room_id' => null,
                    'capacity' => self::CLASS_GROUP_CAPACITY,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $ids[] = (int) $newId;
                $existingLetters[] = $letter;
            } catch (UniqueConstraintViolationException) {
                // Lost a race to a concurrent agent; re-fetch and use theirs.
                $winnerId = DB::table('class_groups')
                    ->where('academic_year_id', $this->academicYearId)
                    ->where('class_level_id', $levelId)
                    ->where('name', $groupName)
                    ->value('id');

                if (is_numeric($winnerId)) {
                    $ids[] = (int) $winnerId;
                    $existingLetters[] = $letter;
                }
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $streamIds
     * @param  array{0: int, 1: int}  $ageRange
     */
    private function createStudentsForLevel(int $levelId, array $streamIds, int $toCreate, array $ageRange): int
    {
        if ($streamIds === []) {
            return 0;
        }

        [$minAge, $maxAge] = $ageRange;

        // Track live occupancy per class group so we round-robin without
        // overfilling any single stream beyond capacity.
        $occupancy = [];

        foreach ($streamIds as $streamGroupId) {
            $occupancy[$streamGroupId] = DB::table('enrollment_segments')
                ->where('class_group_id', $streamGroupId)
                ->whereNull('ends_on')
                ->count();
        }

        $created = 0;
        $attempts = 0;
        $maxAttempts = $toCreate * 3 + 20;

        // Start numbering after the current highest sequence used by either
        // seeder so re-runs (and runs alongside the baseline seeder) never
        // collide on matricule/admission_no.
        $sequence = $this->nextSequence();

        while ($created < $toCreate && $attempts < $maxAttempts) {
            $attempts++;

            $groupId = $this->pickGroupWithRoom($streamIds, $occupancy);

            if ($groupId === null) {
                break; // all streams for this level are full
            }

            $i = $sequence + $created;
            $gender = random_int(0, 1) === 0 ? StudentGender::Male : StudentGender::Female;
            $firstName = $gender === StudentGender::Male
                ? $this->maleFirstNames[array_rand($this->maleFirstNames)]
                : $this->femaleFirstNames[array_rand($this->femaleFirstNames)];
            $lastName = $this->lastNames[array_rand($this->lastNames)];

            $matricule = sprintf('OS-26-%04d', $i);
            $admissionNo = sprintf('HA/ADM/2026/%04d', $i);

            if (Student::query()->where('matricule', $matricule)->exists()) {
                $sequence++;

                continue;
            }

            $age = random_int($minAge, $maxAge);
            $birthYear = 2026 - $age;
            $dob = sprintf('%04d-%02d-%02d', $birthYear, random_int(1, 12), random_int(1, 28));

            $neighbourhood = $this->doualaNeighbourhoods[array_rand($this->doualaNeighbourhoods)];
            $street = $this->streetNames[array_rand($this->streetNames)];
            $addressLine = $street.', '.$neighbourhood;

            $student = Student::query()->create([
                'matricule' => $matricule,
                'matricule_is_official' => true,
                'admission_no' => $admissionNo,
                'first_name' => $firstName,
                'middle_name' => null,
                'last_name' => $lastName,
                'preferred_name' => null,
                'date_of_birth' => $dob,
                'birth_certificate_no' => null,
                'place_of_birth' => 'Douala',
                'gender' => $gender,
                'nationality' => 'CM',
                'state_of_origin' => ['Centre', 'Littoral', 'North West', 'South West', 'West', 'Far North'][random_int(0, 5)],
                'religion' => null,
                'blood_group' => null,
                'genotype' => null,
                'national_id_number' => null,
                'national_id_blind_index' => null,
                'photo_path' => null,
                'phone' => null,
                'email' => null,
                'address_line' => $addressLine,
                'city' => 'Douala',
                'region' => 'Littoral',
                'house_id' => null,
                'status' => StudentStatus::Active,
                'first_admission_date' => '2026-09-05',
                'left_on' => null,
                'deceased_on' => null,
                'is_archived' => false,
                'created_by' => null,
                'updated_by' => null,
            ]);

            $this->attachGuardians($student, $lastName, $addressLine, $neighbourhood);

            $boardingStatus = random_int(1, 100) <= 35 ? 'boarder' : 'day';

            try {
                app(EnrollStudent::class)->handle(
                    studentId: (int) $student->id,
                    academicYearId: $this->academicYearId,
                    classGroupId: $groupId,
                    enrolledOn: '2026-09-05',
                    boardingStatus: $boardingStatus,
                );
            } catch (ValidationException $e) {
                // Most likely capacity was hit concurrently or by our own
                // occupancy drift; mark this group full and retry another.
                $occupancy[$groupId] = self::CLASS_GROUP_CAPACITY;

                continue;
            }

            $occupancy[$groupId] = ($occupancy[$groupId] ?? 0) + 1;
            $created++;
            $sequence++;
        }

        return $created;
    }

    private function nextSequence(): int
    {
        $maxMatricule = DB::table('students')
            ->where('matricule', 'like', 'OS-26-%')
            ->orderByDesc('matricule')
            ->value('matricule');

        if (! is_string($maxMatricule)) {
            return 1;
        }

        $numeric = (int) Str::afterLast($maxMatricule, '-');

        return $numeric + 1;
    }

    /**
     * @param  list<int>  $streamIds
     * @param  array<int, int>  $occupancy
     */
    private function pickGroupWithRoom(array $streamIds, array $occupancy): ?int
    {
        // Least-full-first keeps streams roughly even.
        $candidates = array_filter(
            $streamIds,
            fn (int $id): bool => ($occupancy[$id] ?? 0) < self::CLASS_GROUP_CAPACITY,
        );

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (int $a, int $b): int => ($occupancy[$a] ?? 0) <=> ($occupancy[$b] ?? 0));

        return $candidates[0];
    }

    private function attachGuardians(Student $student, string $lastName, string $addressLine, string $neighbourhood): void
    {
        // ~18% of students are siblings of an earlier-created student this
        // run: reuse that family's guardians instead of minting new ones.
        if ($this->familyPool !== [] && random_int(1, 100) <= 18) {
            $familyKeys = array_keys($this->familyPool);
            $key = $familyKeys[array_rand($familyKeys)];
            $guardians = $this->familyPool[$key];

            foreach ($guardians as $g => $guardian) {
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
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'revocation_reason' => null,
                    'created_by' => null,
                    'updated_by' => null,
                ]);
            }

            return;
        }

        // 1-2 guardians, weighted toward 2.
        $guardianCount = random_int(1, 100) <= 70 ? 2 : 1;
        $createdGuardians = [];

        for ($g = 0; $g < $guardianCount; $g++) {
            $guardianGender = $g === 0 ? 'male' : 'female';
            $guardianFirst = $guardianGender === 'male'
                ? $this->maleFirstNames[array_rand($this->maleFirstNames)]
                : $this->femaleFirstNames[array_rand($this->femaleFirstNames)];

            $relationship = $g === 0
                ? GuardianRelationship::Father
                : GuardianRelationship::Mother;

            // A slice of single-guardian records are a non-parent guardian.
            if ($guardianCount === 1 && random_int(1, 100) <= 15) {
                $relationship = [GuardianRelationship::Grandparent, GuardianRelationship::Uncle, GuardianRelationship::Aunt, GuardianRelationship::LegalGuardian][array_rand([0, 1, 2, 3])];
            }

            /** @var Guardian $guardian */
            $guardian = Guardian::query()->create([
                'guardian_no' => 'GRD-'.Str::upper(Str::random(8)),
                'title' => $guardianGender === 'male' ? 'Mr.' : 'Mrs.',
                'first_name' => $guardianFirst,
                'last_name' => $lastName,
                'date_of_birth' => sprintf('19%d-0%d-1%d', 68 + random_int(0, 20), 1 + random_int(0, 8), 1 + random_int(0, 7)),
                'gender' => $guardianGender === 'male' ? Gender::Male : Gender::Female,
                'nationality' => 'CM',
                'id_type' => GuardianIdType::NationalId,
                'id_number' => null,
                'id_number_blind_index' => null,
                'occupation' => $this->occupations[array_rand($this->occupations)],
                'employer' => null,
                'marital_status' => MaritalStatus::Married,
                'phone' => PhoneNumber::normalise('6'.random_int(10000000, 99999999)),
                'alternative_phone' => null,
                'email' => null,
                'address_line' => $addressLine,
                'city' => 'Douala',
                'region' => 'Littoral',
                'country' => 'Cameroon',
                'residential_status' => ResidentialStatus::OwnHouse,
                'preferred_contact_method' => PreferredContactMethod::Phone,
                'language' => GuardianLanguage::English,
                'emergency_contact_name' => $guardianFirst.' '.$lastName,
                'emergency_contact_phone' => PhoneNumber::normalise('6'.random_int(10000000, 99999999)),
                'emergency_contact_relationship' => $relationship->value,
                'emergency_contact_address' => $addressLine,
                'photo_path' => null,
                'status' => GuardianStatus::Active,
                'notify_sms' => true,
                'notify_email' => random_int(0, 1) === 1,
                'notify_push' => false,
                'receives_reports' => true,
                'receives_invoices' => true,
                'portal_user_id' => null,
                'is_archived' => false,
            ]);

            StudentGuardian::query()->create([
                'student_id' => $student->id,
                'guardian_id' => $guardian->id,
                'relationship' => $relationship,
                'relationship_other' => null,
                'is_primary' => $g === 0,
                'has_custody' => $g === 0,
                'receives_reports' => true,
                'receives_invoices' => true,
                'is_emergency_contact' => true,
                'is_authorised_for_pickup' => true,
                'is_fee_payer' => $g === 0,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'revocation_reason' => null,
                'created_by' => null,
                'updated_by' => null,
            ]);

            $createdGuardians[] = $guardian;
        }

        // Keep the family pool bounded so memory doesn't grow unbounded
        // across a ~900-student run.
        if (count($this->familyPool) >= 150) {
            array_shift($this->familyPool);
        }

        $this->familyPool[] = $createdGuardians;
    }

    private function demoAdmin(): User
    {
        $user = User::query()->where('email', 'demo.admin@opeschool.test')->first();

        if ($user !== null) {
            return $user;
        }

        // Fall back to any existing admin-ish user so this seeder can run
        // standalone if DemoDataSeeder's admin wasn't created under that
        // exact email for some reason.
        $fallback = User::query()->first();

        if ($fallback !== null) {
            return $fallback;
        }

        throw new \RuntimeException('No user found to act as seeder actor; run DemoDataSeeder first.');
    }
}
