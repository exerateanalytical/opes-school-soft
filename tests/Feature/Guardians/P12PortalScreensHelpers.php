<?php

declare(strict_types=1);

// Shared fixtures for the P12-P2 guardian/staff portal SCREENS suite
// (GuardianPortalResultsTest, GuardianPortalFeesTest,
// GuardianDenyByDefaultRouteEnumerationTest, StaffPortalTest).
// Every helper is p12scr-prefixed and function_exists-guarded per the
// parallel-agent convention: Pest includes every suite file before running
// any test, and names must stay globally unique across agents.

use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Assessment\Models\PeriodPublication;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\InvoiceLine;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Domain\Permission as OpesPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

if (! function_exists('p12scrGrantPortalAccess')) {
    /** Seeds the `guardian`/`staff_portal` role -> `portal.access` grant once. */
    function p12scrGrantPortalAccess(string $roleName): void
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $access = Permission::findOrCreate(OpesPermission::PortalAccess->value, 'web');
        SpatieRole::findOrCreate($roleName, 'web')->givePermissionTo($access);
    }
}

if (! function_exists('p12scrPortalGuardian')) {
    /**
     * An activated guardian portal principal, acting-as by default.
     *
     * @return array{user: User, guardian: Guardian}
     */
    function p12scrPortalGuardian(bool $login = true): array
    {
        p12scrGrantPortalAccess('guardian');

        $user = User::factory()->create();
        $user->assignRole('guardian');
        $user = $user->fresh() ?? $user;

        $guardian = Guardian::factory()->create(['portal_user_id' => $user->getKey()]);

        if ($login) {
            actingAs($user);
        }

        return ['user' => $user, 'guardian' => $guardian];
    }
}

if (! function_exists('p12scrStudent')) {
    /** @param array<string, mixed> $overrides */
    function p12scrStudent(array $overrides = []): int
    {
        $suffix = Str::upper(Str::random(6));

        return (int) DB::table('students')->insertGetId(array_merge([
            'matricule' => 'P12S-'.$suffix,
            'admission_no' => 'ADM/P12S/'.$suffix,
            'first_name' => 'Portal',
            'last_name' => 'Screens',
            'date_of_birth' => '2012-05-01',
            'gender' => 'male',
            'nationality' => 'CM',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}

if (! function_exists('p12scrLink')) {
    /** @param array<string, mixed> $flags */
    function p12scrLink(int $guardianId, int $studentId, array $flags = []): StudentGuardian
    {
        return StudentGuardian::factory()->create(array_merge([
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
        ], $flags));
    }
}

if (! function_exists('p12scrEnrollmentFor')) {
    function p12scrEnrollmentFor(int $studentId): Enrollment
    {
        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::factory()->create(['student_id' => $studentId]);

        return $enrollment;
    }
}

if (! function_exists('p12scrPublishedSnapshot')) {
    /**
     * A `report_card_snapshots` row sitting under a PUBLISHED
     * `period_publications` row - the only shape Results.php will ever
     * read (01-assessment 13.3: never live).
     *
     * @param  array<string, mixed>  $payload
     */
    function p12scrPublishedSnapshot(int $enrollmentId, array $payload, string $startsOn = '2026-09-01'): int
    {
        /** @var AssessmentPeriod $period */
        $period = AssessmentPeriod::factory()->create([
            'name' => 'Sequence '.Str::random(4),
            'name_fr' => 'Séquence '.Str::random(4),
            'starts_on' => $startsOn,
            'ends_on' => \Illuminate\Support\Carbon::parse($startsOn)->addDays(30)->toDateString(),
        ]);

        /** @var ClassGroup $classGroup */
        $classGroup = ClassGroup::factory()->create();

        // The `chk_period_publications_version_pinned` check requires
        // `report_card_config_version_id IS NOT NULL` from `published`
        // onwards (01-assessment 13.1), so the config/version rows must
        // exist BEFORE the publication row is inserted with that status -
        // not after, which is what a fixed-column-order factory call would
        // otherwise tempt you into.
        $configId = DB::table('report_card_configs')->insertGetId([
            'framework_id' => null,
            'code' => 'P12S'.Str::upper(Str::random(4)),
            'name' => 'p12scr fixture config',
            'name_fr' => 'Config p12scr',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = DB::table('report_card_config_versions')->insertGetId([
            'config_id' => $configId,
            'version_no' => 1,
            'payload' => json_encode(['layout' => 'p12scr', 'blocks' => [], 'marks_columns' => []], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', 'p12scr-fixture'),
            'frozen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var PeriodPublication $publication */
        $publication = PeriodPublication::factory()
            ->forPeriod((int) $period->getKey())
            ->forClassGroup((int) $classGroup->getKey())
            ->create([
                'status' => PeriodPublication::STATUS_PUBLISHED,
                'report_card_config_version_id' => $versionId,
            ]);

        return (int) DB::table('report_card_snapshots')->insertGetId([
            'enrollment_id' => $enrollmentId,
            'assessment_period_id' => $period->getKey(),
            'class_group_id' => $classGroup->getKey(),
            'period_publication_id' => $publication->getKey(),
            'generation' => 1,
            'snapshot_batch_id' => (string) Str::uuid(),
            'report_card_config_version_id' => $versionId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'issued_at' => now(),
            'pdf_hash' => null,
            'applied_policy_notes' => null,
            'superseded_by_snapshot_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p12scrReportCardPayload')) {
    /**
     * @return array<string, mixed>
     */
    function p12scrReportCardPayload(string $studentName, string $average = '15.50', ?int $rankPosition = 3, int $denominator = 40): array
    {
        return [
            'schema' => 'opes.report_card.v1',
            'student' => ['first_name' => $studentName],
            'subjects' => [
                [
                    'subject_allocation_id' => 1,
                    'subject_name' => 'Mathematics',
                    'subject_name_fr' => 'Mathématiques',
                    'subject_score' => '14.00',
                    'appreciation' => 'Good',
                ],
            ],
            'general_average' => [
                'raw' => $average,
                'rounded' => $average,
                'display' => $average,
                'is_pass' => true,
                'is_assessed' => true,
                'subjects_counted' => 1,
            ],
            'mention' => 'Good',
            'gpa' => '3.50',
            'rank' => [
                'position' => $rankPosition,
                'denominator' => $denominator,
                'is_ranked' => $rankPosition !== null,
                'nc_reason' => null,
                'cohort_rule' => 'class',
            ],
        ];
    }
}

if (! function_exists('p12scrInvoice')) {
    function p12scrInvoice(int $enrollmentId, int $amount, string $issueDate = '2026-09-10'): int
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::factory()
            ->issued('P12S-INV-'.Str::upper(Str::random(6)))
            ->create([
                'enrollment_id' => $enrollmentId,
                'issue_date' => $issueDate,
                'due_date' => date('Y-m-d', strtotime($issueDate.' +30 days')),
            ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->getKey(),
            'amount' => $amount,
            'tax_amount' => 0,
        ]);

        return (int) $invoice->getKey();
    }
}

if (! function_exists('p12scrFiscalYearId')) {
    /**
     * Self-provisioned, not borrowed from Accounting's own test helpers:
     * this suite must stay green whether it runs alone or with the full
     * Feature tree (00-core §8's fiscal/academic year split - a fiscal year
     * carries no FK to academic_year_id at all).
     */
    function p12scrFiscalYearId(): int
    {
        $existing = DB::table('fiscal_years')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return (int) DB::table('fiscal_years')->insertGetId([
            'code' => 'P12S'.random_int(1000, 9999),
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'status' => 'open',
            'is_first_exercice' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p12scrPayment')) {
    function p12scrPayment(int $studentId, int $enrollmentId, int $amount, ?string $payerPhone = null, string $valueDate = '2026-09-15'): int
    {
        $academicYearId = DB::table('academic_years')->value('id');

        if (! is_numeric($academicYearId)) {
            $academicYearId = DB::table('academic_years')->insertGetId([
                'code' => '2026-2027-P12S',
                'name' => 'p12scr fixture year',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-07-31',
                'is_current' => false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $fiscalYearId = p12scrFiscalYearId();

        return (int) DB::table('payments')->insertGetId([
            'receipt_no' => 'P12S-RCT-'.Str::upper(Str::random(6)),
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'academic_year_id' => $academicYearId,
            'fiscal_year_id' => $fiscalYearId,
            'payment_method' => 'cash',
            'amount' => $amount,
            'fee_amount' => 0,
            'fee_bearer' => 'none',
            'payer_name' => 'Portal Screens Payer',
            'payer_phone' => $payerPhone,
            'value_date' => $valueDate,
            'posting_date' => $valueDate,
            'clearing_state' => 'cleared',
            'unallocated_amount' => 0,
            'is_migration' => false,
            'received_by' => p12scrAnyStaffUserId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p12scrAnyStaffUserId')) {
    function p12scrAnyStaffUserId(): int
    {
        $existing = DB::table('users')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        /** @var User $user */
        $user = User::factory()->create();

        return (int) $user->getKey();
    }
}

if (! function_exists('p12scrStaffPortalUser')) {
    /**
     * An activated staff portal principal: active user + `staff_portal`
     * role holding `portal.access` + an active `staff_members` row pointing
     * back via `portal_user_id`.
     *
     * @return array{user: User, staffId: int}
     */
    function p12scrStaffPortalUser(bool $login = true): array
    {
        p12scrGrantPortalAccess('staff_portal');

        $user = User::factory()->create();
        $user->assignRole('staff_portal');
        $user = $user->fresh() ?? $user;

        /** @var StaffMember $staff */
        $staff = StaffMember::factory()->create(['portal_user_id' => $user->getKey()]);

        if ($login) {
            actingAs($user);
        }

        return ['user' => $user, 'staffId' => (int) $staff->getKey()];
    }
}
