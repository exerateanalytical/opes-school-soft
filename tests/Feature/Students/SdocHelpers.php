<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
 * Shared fixtures for the student front-desk document suites
 * (StudentFormsRenderTest, StudentCertificatesRenderTest). Every helper is
 * sdoc-prefixed and function_exists-guarded per the parallel-agent
 * convention. Mirrors tests/Feature/Reporting/P13MoneyHelpers.php for the
 * profile/fiscal singletons so the two suites shape the same world.
 */

if (! function_exists('sdocUserAs')) {
    /** A logged-in user holding the union of the given roles' permissions. */
    function sdocUserAs(Role ...$roles): User
    {
        (new Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role->value);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('sdocDocumentProfile')) {
    /**
     * The school_document_profiles singleton (id = 1). State header ON by
     * default here, unlike the money suite: the §7 certificates are
     * state_header = default_on templates and the render should exercise
     * the §2.1 text block.
     *
     * @param  array<string, mixed>  $overrides
     */
    function sdocDocumentProfile(array $overrides = []): void
    {
        DB::table('school_document_profiles')->updateOrInsert(['id' => 1], array_merge([
            'state_header_enabled' => true,
            'ministry_en' => 'MINISTRY OF SECONDARY EDUCATION',
            'ministry_fr' => 'MINISTÈRE DES ENSEIGNEMENTS SECONDAIRES',
            'regional_delegation_en' => 'REGIONAL DELEGATION FOR THE NORTH WEST',
            'regional_delegation_fr' => 'DÉLÉGATION RÉGIONALE DU NORD-OUEST',
            'divisional_delegation_en' => 'DIVISIONAL DELEGATION FOR MEZAM',
            'divisional_delegation_fr' => 'DÉLÉGATION DÉPARTEMENTALE DE LA MEZAM',
            'default_document_language' => 'en',
            'bilingual_documents' => false,
            'crest_path' => null,
            'logo_path' => null,
            'principal_signature_path' => null,
            'registrar_signature_path' => null,
            'school_stamp_path' => null,
            'books_cote_paraphe_reference' => null,
            'paraphe_authority' => null,
            'paraphe_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}

if (! function_exists('sdocConfirmedFiscalIdentity')) {
    /**
     * A confirmed fiscal identity so FiscalIdentityGate::isProvisional() is
     * false and no SPECIMEN watermark lands on the rendered certificates.
     *
     * @param  array<string, mixed>  $overrides
     */
    function sdocConfirmedFiscalIdentity(array $overrides = []): void
    {
        DB::table('fiscal_identities')->updateOrInsert(['id' => 1], array_merge([
            'legal_name' => 'Hope Academy',
            'legal_form' => 'etablissement_prive_laic',
            'niu' => 'M012345678901A',
            'niu_issued_on' => '2018-01-01',
            'rccm_number' => 'RC/DLA/2018/B/1234',
            'rccm_registry' => 'Douala',
            'rccm_registered_on' => '2018-01-01',
            'tax_centre_code' => 'CIME-DLA1',
            'tax_centre_name' => 'CIME Douala 1er',
            'tax_centre_type' => 'CIME',
            'tax_regime' => 'reel',
            'tax_regime_effective_from' => '2018-01-01',
            'is_tva_registered' => true,
            'tva_registered_from' => '2018-01-01',
            'ministry_accreditation_number' => 'ARR-2018-0099',
            'ministry_accreditation_authority' => 'MINESEC',
            'ministry_accreditation_date' => '2018-01-01',
            'ministry_accreditation_expires_on' => null,
            'ministry_accreditation_document_id' => null,
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
            'fiscal_identity_confirmed_by' => User::factory()->create()->getKey(),
            'fiscal_identity_confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}

if (! function_exists('sdocEnrollment')) {
    /**
     * An enrollment WITH its class-group segment - the full shape every §7
     * certificate reads: student, year, level, section (from the factory)
     * plus a class group in that year/level and one open segment (or one
     * closed on left_on for terminal states).
     *
     * @param  array<string, mixed>  $state
     * @return array{enrollment: Enrollment, class_group_id: int, class_group_name: string}
     */
    function sdocEnrollment(array $state = []): array
    {
        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::factory()->create($state);

        $name = 'Form 1 '.Str::upper(Str::random(4));

        $groupId = (int) DB::table('class_groups')->insertGetId([
            'class_level_id' => $enrollment->class_level_id,
            'stream_id' => null,
            'academic_year_id' => $enrollment->academic_year_id,
            'name' => $name,
            'capacity' => 60,
            'status' => 'active',
            'attendance_mode' => 'daily',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enrollment_segments')->insert([
            'enrollment_id' => $enrollment->id,
            'class_group_id' => $groupId,
            'starts_on' => $enrollment->enrolled_on,
            'ends_on' => $enrollment->left_on,
            'reason' => 'initial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'enrollment' => $enrollment,
            'class_group_id' => $groupId,
            'class_group_name' => $name,
        ];
    }
}

if (! function_exists('sdocRegister')) {
    /**
     * One SUBMITTED daily attendance register for the class group, with the
     * given exception rows for the enrollment (07-students §9.4: present is
     * never written).
     *
     * @param  list<array{enrollment_id: int, status: string}>  $exceptions
     */
    function sdocRegister(int $classGroupId, int $academicYearId, string $date, User $taker, array $exceptions = []): int
    {
        $registerId = (int) DB::table('attendance_registers')->insertGetId([
            'class_group_id' => $classGroupId,
            'academic_year_id' => $academicYearId,
            'date' => $date,
            'session' => 'full_day',
            'timetable_slot_id' => 0,
            'subject_id' => null,
            'mode' => 'daily',
            'expected_count' => 30,
            'present_count' => 30 - count($exceptions),
            'absent_count' => count($exceptions),
            'late_count' => 0,
            'excused_count' => 0,
            'status' => 'submitted',
            'taken_by' => $taker->getKey(),
            'taken_at' => now(),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($exceptions as $exception) {
            DB::table('attendance_records')->insert([
                'attendance_register_id' => $registerId,
                'enrollment_id' => $exception['enrollment_id'],
                'status' => $exception['status'],
                'is_justified' => false,
                'recorded_by' => $taker->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $registerId;
    }
}

if (! function_exists('sdocDisciplineCase')) {
    /** An OPEN, non-positive discipline case at the given category severity. */
    function sdocDisciplineCase(int $studentId, ?int $enrollmentId, int $severity, User $reporter, string $status = 'open'): int
    {
        $categoryId = (int) DB::table('discipline_categories')->insertGetId([
            'name' => 'Sdoc category '.Str::upper(Str::random(6)),
            'name_fr' => null,
            'severity' => $severity,
            'default_sanction_type' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('discipline_cases')->insertGetId([
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'discipline_category_id' => $categoryId,
            'occurred_on' => now()->toDateString(),
            'reported_by' => $reporter->getKey(),
            'description' => 'Fixture case for the certificate gate.',
            'status' => $status,
            'visibility' => 'internal',
            'is_positive' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
