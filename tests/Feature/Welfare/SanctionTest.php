<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\ReinstateEnrollment;
use App\Modules\Welfare\Actions\AcknowledgeSanction;
use App\Modules\Welfare\Actions\ApplySanction;
use App\Modules\Welfare\Actions\GetDisciplineCountsForEnrollments;
use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Domain\SanctionLadder;
use App\Modules\Welfare\Domain\SanctionType;
use App\Modules\Welfare\Models\DisciplineCase;
use App\Modules\Welfare\Models\DisciplineCategory;
use App\Modules\Welfare\Models\DisciplineSanction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/DisciplineTestHelpers.php';

uses(RefreshDatabase::class);

// ── ApplySanction ──────────────────────────────────────────────────────────

it('applies a warning and writes the sanction_applied activity row', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    $sanction = app(ApplySanction::class)->handle(
        caseId: (int) $case->getKey(),
        type: SanctionType::Warning,
        startsOn: '2026-03-12',
    );

    expect($sanction->type)->toBe(SanctionType::Warning)
        ->and($sanction->ends_on)->toBeNull()
        ->and($sanction->acknowledged_at)->toBeNull();

    expect(DB::table('student_activity_logs')
        ->where('student_id', $enrollment->student_id)
        ->where('event', 'sanction_applied')
        ->exists())->toBeTrue();

    // A warning never touches the enrollment lifecycle.
    expect(DB::table('enrollments')->where('id', $enrollment->getKey())->value('status'))
        ->toBe('active');
});

it('suspension goes through the Students door: enrollment flips to suspended, never written directly', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    app(ApplySanction::class)->handle(
        caseId: (int) $case->getKey(),
        type: SanctionType::Suspension,
        startsOn: '2026-03-12',
        endsOn: '2026-03-19',
    );

    // The door's side effects, not a bare column write: status flipped AND
    // the lifecycle events the Students module owns were recorded.
    expect(DB::table('enrollments')->where('id', $enrollment->getKey())->value('status'))
        ->toBe('suspended');
    expect(DB::table('student_activity_logs')
        ->where('student_id', $enrollment->student_id)
        ->where('event', 'suspended')
        ->exists())->toBeTrue();

    // The segment stays OPEN: a suspended student remains on the class roll
    // (9.6 handles the days via record status, not by unrolling them).
    expect(DB::table('enrollment_segments')
        ->where('enrollment_id', $enrollment->getKey())
        ->whereNull('ends_on')
        ->exists())->toBeTrue();

    // And the Students door can bring the student back.
    app(ReinstateEnrollment::class)->handle((int) $enrollment->getKey(), 'Suspension served.');
    expect(DB::table('enrollments')->where('id', $enrollment->getKey())->value('status'))
        ->toBe('active');
});

it('refuses an unbounded suspension, a suspension without an enrollment, and inverted dates', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $apply = app(ApplySanction::class);

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    // Suspension with no end date is an exclusion wearing the wrong label.
    expect(fn () => $apply->handle((int) $case->getKey(), SanctionType::Suspension, '2026-03-12'))
        ->toThrow(ValidationException::class);

    // ends_on before starts_on.
    expect(fn () => $apply->handle((int) $case->getKey(), SanctionType::Detention, '2026-03-12', '2026-03-10'))
        ->toThrow(ValidationException::class);

    // A case with no enrollment cannot suspend anyone.
    $unlinked = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => null,
    ]);

    expect(fn () => $apply->handle((int) $unlinked->getKey(), SanctionType::Suspension, '2026-03-12', '2026-03-19'))
        ->toThrow(ValidationException::class);
});

it('refuses to sanction a closed case or a positive entry', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $apply = app(ApplySanction::class);

    $resolved = DisciplineCase::factory()->resolved()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    expect(fn () => $apply->handle((int) $resolved->getKey(), SanctionType::Warning, '2026-03-12'))
        ->toThrow(ValidationException::class);

    $positive = DisciplineCase::factory()->positive()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    expect(fn () => $apply->handle((int) $positive->getKey(), SanctionType::Warning, '2026-03-12'))
        ->toThrow(ValidationException::class);
});

// ── SanctionLadder (advisory only) ─────────────────────────────────────────

it('suggests escalation from prior-case count and never below the category default', function () {
    $ladder = new SanctionLadder();

    expect($ladder->suggest(0))->toBe(SanctionType::Warning)
        ->and($ladder->suggest(1))->toBe(SanctionType::Detention)
        ->and($ladder->suggest(2))->toBe(SanctionType::GuardianSummons)
        ->and($ladder->suggest(3))->toBe(SanctionType::Suspension)
        ->and($ladder->suggest(4))->toBe(SanctionType::Exclusion)
        // Tops out; never walks off the ladder.
        ->and($ladder->suggest(40))->toBe(SanctionType::Exclusion)
        // A grave category starts higher and the count escalates from there.
        ->and($ladder->suggest(0, SanctionType::Suspension))->toBe(SanctionType::Suspension)
        ->and($ladder->suggest(1, SanctionType::Suspension))->toBe(SanctionType::Exclusion);
});

it('computes the suggestion from cross-year student history, excluding dismissed and positive rows', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $category = DisciplineCategory::factory()->create([
        'default_sanction_type' => SanctionType::Warning,
    ]);

    // One prior countable case within the lookback window...
    DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
        'discipline_category_id' => $category->getKey(),
        'occurred_on' => '2025-11-05',
    ]);

    // ...and two that must not count: dismissed, positive.
    DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
        'discipline_category_id' => $category->getKey(),
        'occurred_on' => '2025-12-01',
        'status' => DisciplineCaseStatus::Dismissed,
    ]);
    DisciplineCase::factory()->positive()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
        'discipline_category_id' => $category->getKey(),
        'occurred_on' => '2025-12-02',
    ]);

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
        'discipline_category_id' => $category->getKey(),
        'occurred_on' => '2026-03-10',
    ]);

    // 1 countable prior => one rung above warning.
    expect(app(ApplySanction::class)->suggestionFor((int) $case->getKey()))
        ->toBe(SanctionType::Detention);
});

// ── AcknowledgeSanction ────────────────────────────────────────────────────

it('records the guardian acknowledgement exactly once', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);
    $sanction = DisciplineSanction::factory()->create([
        'discipline_case_id' => $case->getKey(),
    ]);

    $sanction = app(AcknowledgeSanction::class)->handle((int) $sanction->getKey());
    expect($sanction->acknowledged_at)->not->toBeNull();

    // WHEN the guardian signed is evidentiary; a second call refuses rather
    // than rewriting it.
    expect(fn () => app(AcknowledgeSanction::class)->handle((int) $sanction->getKey()))
        ->toThrow(ValidationException::class);
});

// ── §9.7 consignes / exclusions rollup ─────────────────────────────────────

it('counts consignes (detention + consigne) and exclusions within a date window', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    foreach ([
        [SanctionType::Detention, '2026-01-10'],
        [SanctionType::Consigne, '2026-02-14'],
        [SanctionType::Exclusion, '2026-03-01'],
        [SanctionType::Warning, '2026-02-01'],   // wrong type: never counted
        [SanctionType::Detention, '2026-06-10'], // outside the window
    ] as [$type, $date]) {
        DisciplineSanction::factory()->create([
            'discipline_case_id' => $case->getKey(),
            'type' => $type,
            'starts_on' => $date,
        ]);
    }

    $counts = app(GetDisciplineCountsForEnrollments::class)->sanctionCountsBetween(
        [(int) $enrollment->getKey()],
        '2026-01-01',
        '2026-03-31',
    );

    expect($counts[(int) $enrollment->getKey()])->toBe(['consignes' => 2, 'exclusions' => 1]);
});
