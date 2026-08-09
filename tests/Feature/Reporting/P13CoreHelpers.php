<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
 * Shared fixtures for the Phase 13 reporting-core suite. Every helper is
 * p13core-prefixed and function_exists-guarded per the parallel-agent
 * convention: Pest includes every suite file before running any test.
 */

if (! function_exists('p13coreViews')) {
    /** Register the test-local Blade fixtures (p13core-snapshot etc.). */
    function p13coreViews(): void
    {
        View::addLocation(__DIR__.'/views');
    }
}

if (! function_exists('p13coreUserAs')) {
    /** A logged-in user holding the union of the given roles' permissions. */
    function p13coreUserAs(Role ...$roles): User
    {
        (new RolePermissionSeeder)->run();
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role->value);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p13coreDocumentProfile')) {
    /**
     * The school_document_profiles singleton row (id = 1), created the way
     * the settings screen would create it.
     *
     * @param  array<string, mixed>  $overrides
     */
    function p13coreDocumentProfile(array $overrides = []): void
    {
        DB::table('school_document_profiles')->updateOrInsert(['id' => 1], array_merge([
            'state_header_enabled' => true,
            'ministry_en' => 'MINISTRY OF SECONDARY EDUCATION',
            'ministry_fr' => 'MINISTÈRE DES ENSEIGNEMENTS SECONDAIRES',
            'regional_delegation_en' => 'REGIONAL DELEGATION FOR THE CENTRE',
            'regional_delegation_fr' => 'DÉLÉGATION RÉGIONALE DU CENTRE',
            'divisional_delegation_en' => null,
            'divisional_delegation_fr' => null,
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

if (! function_exists('p13coreSnapshotRow')) {
    /**
     * A real report_card_snapshots row (the platform's registered JSON
     * snapshot source), with its FK chain built the cheap way: the
     * publication factory self-provisions period + class group, the
     * enrollment factory self-provisions the student, and the config version
     * pair is inserted directly - this suite tests the DOCUMENT platform,
     * not the publication pipeline that normally writes these rows.
     *
     * @param  array<string, mixed>  $payload
     * @return array{snapshot_id: int, enrollment_id: int}
     */
    function p13coreSnapshotRow(array $payload): array
    {
        $enrollment = App\Modules\Students\Models\Enrollment::factory()->create();

        // A FRESH period + class group per snapshot: period_publications is
        // unique per (period, class group), and the publication factory's
        // any-existing-row shortcut would collide on the second fixture.
        $period = Database\Factories\AssessmentPeriodFactory::new()->createOne();
        $classGroup = Database\Factories\ClassGroupFactory::new()->createOne();

        $publication = App\Modules\Assessment\Models\PeriodPublication::factory()
            ->forPeriod((int) $period->getKey())
            ->forClassGroup((int) $classGroup->getKey())
            ->create();

        $configId = DB::table('report_card_configs')->insertGetId([
            'framework_id' => null,
            'code' => 'P13C'.Str::upper(Str::random(4)),
            'name' => 'p13core fixture config',
            'name_fr' => 'Config de test p13core',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = DB::table('report_card_config_versions')->insertGetId([
            'config_id' => $configId,
            'version_no' => 1,
            'payload' => json_encode(['layout' => 'p13core', 'blocks' => [], 'marks_columns' => []], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', 'p13core-fixture'),
            'frozen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshotId = DB::table('report_card_snapshots')->insertGetId([
            'enrollment_id' => $enrollment->getKey(),
            'assessment_period_id' => $publication->assessment_period_id,
            'class_group_id' => $publication->class_group_id,
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

        return [
            'snapshot_id' => (int) $snapshotId,
            'enrollment_id' => (int) $enrollment->getKey(),
        ];
    }
}

if (! function_exists('p13coreSnapshotPayload')) {
    /**
     * The standard fixture payload: carries its own `school` block (the
     * as-at-issue capture 10-documents 4.2 asks for), so later profile edits
     * cannot move the bytes.
     *
     * @return array<string, mixed>
     */
    function p13coreSnapshotPayload(): array
    {
        return [
            'school' => [
                'name' => 'HOPE ACADEMY',
                'name_fr' => 'COLLÈGE DE L\'ESPOIR',
                'short_code' => 'HA',
                'state_header' => null,
                'branding' => [],
                'fiscal' => ['niu' => 'M012345678901A', 'rccm_number' => null, 'legal_name' => 'Hope Academy'],
                'bilingual' => false,
            ],
            'identity' => [
                'name' => 'AZEMKEU Brice',
                'matricule' => 'HA26-00042',
                'class_group' => 'Form 1A',
                'academic_year' => '2026/2027',
            ],
            'lines' => [
                'Average' => '13.50 / 20',
                'Rank' => '5 / 62',
            ],
        ];
    }
}
