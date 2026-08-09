<?php

declare(strict_types=1);

use App\Modules\Assessment\Models\PeriodPublication;
use App\Modules\Assessment\Models\PeriodResult;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

require_once __DIR__.'/ApiTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('p12apiPublishResult')) {
    /**
     * Flip a period result's class-group publication to `published`.
     *
     * The chk_period_publications_version_pinned constraint requires a pinned
     * report-card config version on any published row (13.1: a published card
     * must stay reproducible), so a real config + version row is created
     * rather than faking the FK.
     */
    function p12apiPublishResult(\App\Modules\Assessment\Models\PeriodResult $result): void
    {
        $config = \Database\Factories\ReportCardConfigFactory::new()->createOne();

        $versionId = \Illuminate\Support\Facades\DB::table('report_card_config_versions')->insertGetId([
            'config_id' => $config->getKey(),
            'version_no' => 1,
            'payload' => json_encode(\Database\Factories\ReportCardConfigFactory::bulletinPayload()),
            'payload_hash' => hash('sha256', 'p12api'),
            'frozen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PeriodPublication::factory()
            ->forPeriod($result->assessment_period_id)
            ->forClassGroup($result->class_group_id)
            ->state([
                'status' => PeriodPublication::STATUS_PUBLISHED,
                'report_card_config_version_id' => $versionId,
                'published_at' => now(),
            ])
            ->create();
    }
}

/*
 * Read-only v1 students/enrollments/results surface
 * (docs/plans/phase-12-13.md 12.4).
 */

it('lists students with pagination meta', function () {
    Student::factory()->count(3)->create();
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    $response = getJson('/api/v1/students', $headers)->assertStatus(200);

    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('meta.page'))->toBe(1);
});

it('filters students by matricule search', function () {
    $needle = Student::factory()->create(['matricule' => 'P12API-FIND-ME']);
    Student::factory()->count(2)->create();
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    $response = getJson('/api/v1/students?search=P12API-FIND', $headers)->assertStatus(200);

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($needle->id);
});

it('caps per_page at 100', function () {
    Student::factory()->create();
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    $response = getJson('/api/v1/students?per_page=5000', $headers)->assertStatus(200);

    expect($response->json('meta.per_page'))->toBe(100);
});

it('shows one student and never exposes the encrypted sensitive fields', function () {
    $student = Student::factory()->create();
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    $response = getJson('/api/v1/students/'.$student->id, $headers)->assertStatus(200);

    expect($response->json('data.id'))->toBe($student->id);
    expect($response->json('data.matricule'))->toBe($student->matricule);

    $keys = array_keys((array) $response->json('data'));
    expect($keys)->not->toContain('national_id_number');
    expect($keys)->not->toContain('religion');
    expect($keys)->not->toContain('blood_group');
    expect($keys)->not->toContain('genotype');
});

it('returns 404 for a missing student', function () {
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    getJson('/api/v1/students/999999', $headers)->assertStatus(404);
});

it('lists enrollments filtered by student', function () {
    $enrollment = Enrollment::factory()->create();
    Enrollment::factory()->count(2)->create();
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    $response = getJson('/api/v1/enrollments?student_id='.$enrollment->student_id, $headers)
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($enrollment->id);
    expect($response->json('data.0.status'))->toBe('active');
});

/*
 * Published results: the publication state is checked FIRST - an unpublished
 * result is unreachable, not hidden (01-assessment 13.2).
 */

it('returns only results whose class-group publication is published', function () {
    /** @var PeriodResult $published */
    $published = PeriodResult::factory()->create();
    p12apiPublishResult($published);

    /** @var PeriodResult $unpublished */
    $unpublished = PeriodResult::factory()->create();
    PeriodPublication::factory()
        ->forPeriod($unpublished->assessment_period_id)
        ->forClassGroup($unpublished->class_group_id)
        ->create(); // default state: marks_open

    /** @var PeriodResult $noPublication */
    $noPublication = PeriodResult::factory()->create();

    $user = p12apiUserWithPermissions(Permission::AcademicsView);
    $headers = p12apiBearerHeaders($user, [Permission::AcademicsView->value]);

    $response = getJson('/api/v1/results', $headers)->assertStatus(200);

    $ids = array_column((array) $response->json('data'), 'id');
    expect($ids)->toContain($published->id);
    expect($ids)->not->toContain($unpublished->id);
    expect($ids)->not->toContain($noPublication->id);
    expect($response->json('data.0.published_at'))->not->toBeNull();
});

it('filters published results by enrollment', function () {
    /** @var PeriodResult $result */
    $result = PeriodResult::factory()->create();
    p12apiPublishResult($result);

    $user = p12apiUserWithPermissions(Permission::AcademicsView);
    $headers = p12apiBearerHeaders($user, [Permission::AcademicsView->value]);

    $hit = getJson('/api/v1/results?enrollment_id='.$result->enrollment_id, $headers)
        ->assertStatus(200);
    expect($hit->json('meta.total'))->toBe(1);

    $miss = getJson('/api/v1/results?enrollment_id=999999', $headers)->assertStatus(200);
    expect($miss->json('meta.total'))->toBe(0);
});

it('denies the results endpoint to a token scoped elsewhere', function () {
    $user = p12apiUserWithPermissions(Permission::AcademicsView, Permission::FeeView);
    $headers = p12apiBearerHeaders($user, [Permission::FeeView->value]);

    getJson('/api/v1/results', $headers)->assertStatus(403);
});
