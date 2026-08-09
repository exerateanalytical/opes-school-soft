<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Welfare\Actions\GetDisciplineCountsForEnrollments;
use App\Modules\Welfare\Actions\OpenDisciplineCase;
use App\Modules\Welfare\Actions\ResolveDisciplineCase;
use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Domain\DisciplineVisibility;
use App\Modules\Welfare\Models\DisciplineCase;
use App\Modules\Welfare\Models\DisciplineCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/DisciplineTestHelpers.php';

uses(RefreshDatabase::class);

// ── OpenDisciplineCase ─────────────────────────────────────────────────────

it('opens a case keyed on BOTH student and the live enrollment for the incident date (C3)', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $category = DisciplineCategory::factory()->create(['severity' => 2]);

    $case = app(OpenDisciplineCase::class)->handle(
        studentId: $enrollment->student_id,
        categoryId: (int) $category->getKey(),
        occurredOn: '2026-03-10',
        description: 'Fighting in the corridor after second period.',
    );

    expect($case->student_id)->toBe($enrollment->student_id)
        ->and($case->enrollment_id)->toBe((int) $enrollment->getKey())
        ->and($case->status)->toBe(DisciplineCaseStatus::Open)
        ->and($case->visibility)->toBe(DisciplineVisibility::Internal)
        ->and($case->is_positive)->toBeFalse();

    // The student's activity feed records the opening (07-students 8.3).
    expect(DB::table('student_activity_logs')
        ->where('student_id', $enrollment->student_id)
        ->where('event', 'discipline_case_opened')
        ->exists())->toBeTrue();
});

it('stores NULL enrollment for an incident outside any enrolled period', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $case = app(OpenDisciplineCase::class)->handle(
        studentId: $enrollment->student_id,
        categoryId: (int) DisciplineCategory::factory()->create()->getKey(),
        // After year end (2026-06-30), before today: no year contains it.
        occurredOn: '2026-07-15',
        description: 'Incident on school premises during the holiday.',
    );

    expect($case->enrollment_id)->toBeNull();
});

it('records a positive behaviour entry as a first-class row', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $case = app(OpenDisciplineCase::class)->handle(
        studentId: $enrollment->student_id,
        categoryId: (int) DisciplineCategory::factory()->create()->getKey(),
        occurredOn: '2026-03-11',
        description: 'Returned a lost wallet with all its contents.',
        visibility: DisciplineVisibility::Guardian,
        isPositive: true,
    );

    expect($case->is_positive)->toBeTrue()
        ->and($case->visibility)->toBe(DisciplineVisibility::Guardian);
});

it('refuses a future incident date', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    app(OpenDisciplineCase::class)->handle(
        studentId: $enrollment->student_id,
        categoryId: (int) DisciplineCategory::factory()->create()->getKey(),
        occurredOn: now()->addDays(3)->toDateString(),
        description: 'Precrime.',
    );
})->throws(ValidationException::class);

it('refuses an empty description and a retired category', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $open = app(OpenDisciplineCase::class);

    expect(fn () => $open->handle(
        studentId: $enrollment->student_id,
        categoryId: (int) DisciplineCategory::factory()->create()->getKey(),
        occurredOn: '2026-03-10',
        description: '   ',
    ))->toThrow(ValidationException::class);

    $retired = DisciplineCategory::factory()->create(['is_active' => false]);

    expect(fn () => $open->handle(
        studentId: $enrollment->student_id,
        categoryId: (int) $retired->getKey(),
        occurredOn: '2026-03-10',
        description: 'Valid description.',
    ))->toThrow(ValidationException::class);
});

it('refuses to open a case without discipline.manage', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    // Principal holds discipline.view but NOT discipline.manage (plan §1).
    actingAs(phase8F3UserAs(Role::Principal));

    app(OpenDisciplineCase::class)->handle(
        studentId: $enrollment->student_id,
        categoryId: (int) DisciplineCategory::factory()->create()->getKey(),
        occurredOn: '2026-03-10',
        description: 'Should never persist.',
    );
})->throws(AuthorizationException::class);

// ── ResolveDisciplineCase ──────────────────────────────────────────────────

it('walks the lifecycle open -> under_investigation -> resolved and stamps the resolution', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    $user = phase8F3UserAs(Role::DisciplineMaster);
    actingAs($user);

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    $resolve = app(ResolveDisciplineCase::class);

    $case = $resolve->handle((int) $case->getKey(), DisciplineCaseStatus::UnderInvestigation);
    expect($case->status)->toBe(DisciplineCaseStatus::UnderInvestigation)
        ->and($case->resolved_at)->toBeNull();

    $case = $resolve->handle((int) $case->getKey(), DisciplineCaseStatus::Resolved, 'Apology and restitution.');
    expect($case->status)->toBe(DisciplineCaseStatus::Resolved)
        ->and($case->resolved_at)->not->toBeNull()
        ->and($case->resolved_by)->toBe($user->getKey())
        ->and($case->resolution_note)->toBe('Apology and restitution.');
});

it('refuses to close a case without a note, refuses reopening, and enforces the graph', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $resolve = app(ResolveDisciplineCase::class);

    $case = DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    // Terminal without a note.
    expect(fn () => $resolve->handle((int) $case->getKey(), DisciplineCaseStatus::Dismissed))
        ->toThrow(ValidationException::class);

    // Back to open is never a transition.
    expect(fn () => $resolve->handle((int) $case->getKey(), DisciplineCaseStatus::Open, 'note'))
        ->toThrow(ValidationException::class);

    $resolve->handle((int) $case->getKey(), DisciplineCaseStatus::Resolved, 'Handled.');

    // Terminal is terminal.
    expect(fn () => $resolve->handle((int) $case->getKey(), DisciplineCaseStatus::Dismissed, 'again'))
        ->toThrow(ValidationException::class);
});

// ── GetDisciplineCountsForEnrollments (read door, plan-fixed signature) ────

it('counts cases and max severity per enrollment, excluding dismissed and positive entries', function () {
    $fixture = phase8F3Fixture();
    $enrollmentA = phase8F3Enroll($fixture);
    $enrollmentB = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    $minor = DisciplineCategory::factory()->create(['severity' => 1]);
    $grave = DisciplineCategory::factory()->grave()->create(); // severity 5

    // Two countable cases for A (severities 1 and 5)...
    DisciplineCase::factory()->create([
        'student_id' => $enrollmentA->student_id,
        'enrollment_id' => $enrollmentA->getKey(),
        'discipline_category_id' => $minor->getKey(),
    ]);
    DisciplineCase::factory()->create([
        'student_id' => $enrollmentA->student_id,
        'enrollment_id' => $enrollmentA->getKey(),
        'discipline_category_id' => $grave->getKey(),
    ]);

    // ...plus a dismissed one and a positive one that must NOT count.
    DisciplineCase::factory()->create([
        'student_id' => $enrollmentA->student_id,
        'enrollment_id' => $enrollmentA->getKey(),
        'discipline_category_id' => $grave->getKey(),
        'status' => DisciplineCaseStatus::Dismissed,
    ]);
    DisciplineCase::factory()->positive()->create([
        'student_id' => $enrollmentA->student_id,
        'enrollment_id' => $enrollmentA->getKey(),
        'discipline_category_id' => $grave->getKey(),
    ]);

    $counts = app(GetDisciplineCountsForEnrollments::class)->handle(
        (int) $fixture['year']->getKey(),
        [(int) $enrollmentA->getKey(), (int) $enrollmentB->getKey()],
    );

    expect($counts[(int) $enrollmentA->getKey()])->toBe(['count' => 2, 'max_severity' => 5])
        // B has no casework: an explicit clean {0, 0}, still present.
        ->and($counts[(int) $enrollmentB->getKey()])->toBe(['count' => 0, 'max_severity' => 0]);
});

it('reports zeros when the enrollment does not belong to the requested year', function () {
    $fixture = phase8F3Fixture();
    $enrollment = phase8F3Enroll($fixture);
    actingAs(phase8F3UserAs(Role::DisciplineMaster));

    DisciplineCase::factory()->create([
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
    ]);

    $wrongYearId = (int) $fixture['year']->getKey() + 999;

    $counts = app(GetDisciplineCountsForEnrollments::class)->handle(
        $wrongYearId,
        [(int) $enrollment->getKey()],
    );

    expect($counts[(int) $enrollment->getKey()])->toBe(['count' => 0, 'max_severity' => 0]);
});
