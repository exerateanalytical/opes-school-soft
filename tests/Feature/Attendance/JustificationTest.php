<?php

declare(strict_types=1);

use App\Modules\Attendance\Actions\JustifyAbsence;
use App\Modules\Attendance\Actions\OpenAttendanceRegister;
use App\Modules\Attendance\Actions\SubmitAttendanceRegister;
use App\Modules\Attendance\Domain\AttendanceStatus;
use App\Modules\Attendance\Domain\JustificationType;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AttendanceTestHelpers.php';

uses(RefreshDatabase::class);

// §9.7 (C6): status, advance excusal and after-the-fact justification are
// three ORTHOGONAL concepts. These tests pin the orthogonality.

if (! function_exists('phase8F2AbsentRecord')) {
    /**
     * A submitted register with one absent student; returns [record, enrollment].
     *
     * @param  array{
     *     year: \App\Modules\Academics\Models\AcademicYear,
     *     section: \App\Modules\Academics\Models\SchoolSection,
     *     level: \App\Modules\Academics\Models\ClassLevel,
     *     group: \App\Modules\Academics\Models\ClassGroup,
     * }  $fixture
     * @return array{AttendanceRecord, Enrollment}
     */
    function phase8F2AbsentRecord(array $fixture, string $status = 'absent'): array
    {
        $enrollments = phase8F2Enroll($fixture, 2);

        $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
        app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
            ['enrollment_id' => (int) $enrollments[0]->getKey(), 'status' => $status],
        ]);

        $record = AttendanceRecord::query()
            ->where('attendance_register_id', $register->getKey())
            ->firstOrFail();

        return [$record, $enrollments[0]];
    }
}

it('justifies an absence AFTER the fact - is_justified flips, the status stays absent', function () {
    $fixture = phase8F2Fixture();
    actingAs(phase8F2UserAs(Role::VicePrincipal));
    [$record] = phase8F2AbsentRecord($fixture);

    expect($record->is_justified)->toBeFalse();

    app(JustifyAbsence::class)->handle((int) $record->getKey(), JustificationType::Family);

    $record->refresh();

    expect($record->is_justified)->toBeTrue()
        ->and($record->status)->toBe(AttendanceStatus::Absent) // NOT excused
        ->and($record->justification_type)->toBe(JustificationType::Family)
        ->and($record->justified_by)->not->toBeNull()
        ->and($record->justified_at)->not->toBeNull();
});

it('keeps excused and justified orthogonal - an excused record may still be justified', function () {
    $fixture = phase8F2Fixture();
    actingAs(phase8F2UserAs(Role::VicePrincipal));
    [$record] = phase8F2AbsentRecord($fixture, 'excused');

    // Excused in advance but not yet justified — a legal combination (§9.7).
    expect($record->status)->toBe(AttendanceStatus::Excused)
        ->and($record->is_justified)->toBeFalse();

    app(JustifyAbsence::class)->handle((int) $record->getKey(), JustificationType::Administrative);

    $record->refresh();
    expect($record->status)->toBe(AttendanceStatus::Excused)
        ->and($record->is_justified)->toBeTrue();
});

it('refuses to justify a record that is not an absence', function () {
    $fixture = phase8F2Fixture();
    actingAs(phase8F2UserAs(Role::VicePrincipal));
    [$record] = phase8F2AbsentRecord($fixture, 'late');

    // Late is present (§9.6); there is no absence to justify.
    expect(fn () => app(JustifyAbsence::class)->handle((int) $record->getKey(), JustificationType::Other))
        ->toThrow(ValidationException::class, 'not an absence');
});

it('requires attendance.justify - a plain teacher cannot justify', function () {
    $fixture = phase8F2Fixture();
    actingAs(phase8F2UserAs(Role::VicePrincipal));
    [$record] = phase8F2AbsentRecord($fixture);

    actingAs(phase8F2Teacher($fixture));

    expect(fn () => app(JustifyAbsence::class)->handle((int) $record->getKey(), JustificationType::Medical))
        ->toThrow(AuthorizationException::class);

    // The Surveillant Général (DisciplineMaster) holds it.
    actingAs(phase8F2UserAs(Role::DisciplineMaster));
    $justified = app(JustifyAbsence::class)->handle((int) $record->getKey(), JustificationType::Medical);
    expect($justified->is_justified)->toBeTrue();
});

it('rejects a supporting document that belongs to a different student', function () {
    $fixture = phase8F2Fixture();
    actingAs(phase8F2UserAs(Role::VicePrincipal));
    [$record] = phase8F2AbsentRecord($fixture);

    // A document owned by some OTHER student.
    $otherStudentId = (int) Enrollment::factory()->create([
        'academic_year_id' => $fixture['year']->getKey(),
        'class_level_id' => $fixture['level']->getKey(),
        'school_section_id' => $fixture['section']->getKey(),
    ])->student_id;

    $documentId = DB::table('student_documents')->insertGetId([
        'student_id' => $otherStudentId,
        'title' => 'Medical certificate',
        'file_path' => 'documents/certificate.pdf',
        'file_hash' => str_repeat('a', 64),
        'mime' => 'application/pdf',
        'size_bytes' => 1024,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(JustifyAbsence::class)->handle(
        (int) $record->getKey(),
        JustificationType::Medical,
        $documentId,
    ))->toThrow(ValidationException::class, 'different');
});
