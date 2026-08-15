<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\PrintAttendanceCertificate;
use App\Modules\Students\Actions\PrintBonafideCertificate;
use App\Modules\Students\Actions\PrintCharacterCertificate;
use App\Modules\Students\Actions\PrintLeavingCertificate;
use App\Modules\Students\Actions\PrintTestimonial;
use App\Modules\Students\Actions\PrintTransferCertificate;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SdocHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §7.6-§7.11 - the six snapshot-backed student
 * certificates: series allocation, DUPLICATA on reprint, the clearance /
 * discipline / empty-denominator refusals and the documents.override_gate
 * path, plus the §2.3 forbidden-strings sweep over every rendered output.
 */
beforeEach(function (): void {
    sdocDocumentProfile();
    sdocConfirmedFiscalIdentity();
});

// ---------------------------------------------------------------- bonafide

it('issues the bonafide certificate with a BON serial and reprints it as a DUPLICATA', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();
    $student = Student::query()->findOrFail($fixture['enrollment']->student_id);

    $first = app(PrintBonafideCertificate::class)->handle($student->id);

    expect((string) $first->serial)->toContain('/BON/');
    expect($first->isDuplicate)->toBeFalse();
    expect($first->html)->toContain($student->last_name);
    expect($first->html)->toContain($fixture['class_group_name']);
    expect($first->html)->toContain('bona fide student');
    expect($first->html)->not->toContain('DUPLICATA');
    // §2.1: state letterhead text, never an emblem.
    expect($first->html)->toContain('REPUBLIC OF CAMEROON');

    $second = app(PrintBonafideCertificate::class)->handle($student->id);

    expect($second->isDuplicate)->toBeTrue();
    expect($second->html)->toContain('DUPLICATA');
    expect($second->issuedDocumentId)->toBe($first->issuedDocumentId);
    expect($second->serial)->toBe($first->serial); // never a second original
});

it('refuses a fresh bonafide for a student with no live enrollment', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment(['status' => 'withdrawn', 'left_on' => '2027-01-15']);

    expect(fn () => app(PrintBonafideCertificate::class)->handle($fixture['enrollment']->student_id))
        ->toThrow(DomainException::class, 'no live enrollment');
});

// ------------------------------------------------------- transfer / leaving

it('blocks the transfer certificate without financial clearance and issues once cleared', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment(['status' => 'transferred_out', 'left_on' => '2027-03-01']);
    $enrollment = $fixture['enrollment'];
    $student = Student::query()->findOrFail($enrollment->student_id);

    expect(fn () => app(PrintTransferCertificate::class)->handle($student->id, 'Family relocation'))
        ->toThrow(DomainException::class, 'Financial clearance');

    DB::table('enrollments')->where('id', $enrollment->id)->update(['financial_clearance' => true]);

    $rendered = app(PrintTransferCertificate::class)->handle($student->id, 'Family relocation');

    expect((string) $rendered->serial)->toContain('/TC/');
    expect($rendered->html)->toContain('Transfer Certificate');
    expect($rendered->html)->toContain($student->last_name);
    expect($rendered->html)->toContain('Family relocation');
});

it('lets the principal override the clearance gate with a reason that is logged and never printed', function (): void {
    sdocUserAs(Role::Principal);
    $fixture = sdocEnrollment(['status' => 'transferred_out', 'left_on' => '2027-03-01']);
    $enrollment = $fixture['enrollment'];
    $student = Student::query()->findOrFail($enrollment->student_id);

    $rendered = app(PrintTransferCertificate::class)->handle(
        $student->id,
        'Family relocation',
        'Settlement plan signed by the bursar on file.',
    );

    expect((string) $rendered->serial)->toContain('/TC/');
    // Printed nowhere...
    expect($rendered->html)->not->toContain('Settlement plan signed');
    // ...logged everywhere.
    $audit = DB::table('audit_logs')
        ->where('action', 'gate_overridden')
        ->where('auditable_type', 'Enrollment')
        ->where('auditable_id', $enrollment->id)
        ->exists();
    expect($audit)->toBeTrue();
});

it('refuses the clearance override to a caller without documents.override_gate', function (): void {
    sdocUserAs(Role::Registrar); // documents.print, but never the override
    $fixture = sdocEnrollment(['status' => 'transferred_out', 'left_on' => '2027-03-01']);

    expect(fn () => app(PrintTransferCertificate::class)->handle(
        $fixture['enrollment']->student_id,
        null,
        'I say so.',
    ))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('blocks the leaving certificate the same way and issues an LC serial when cleared', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment(['status' => 'withdrawn', 'left_on' => '2027-06-30']);
    $enrollment = $fixture['enrollment'];
    $student = Student::query()->findOrFail($enrollment->student_id);

    expect(fn () => app(PrintLeavingCertificate::class)->handle($student->id))
        ->toThrow(DomainException::class, 'Financial clearance');

    DB::table('enrollments')->where('id', $enrollment->id)->update(['financial_clearance' => true]);

    $rendered = app(PrintLeavingCertificate::class)->handle($student->id);

    expect((string) $rendered->serial)->toContain('/LC/');
    expect($rendered->html)->toContain('School Leaving Certificate');
    expect($rendered->html)->toContain($student->last_name);
});

// ----------------------------------------------------------------- conduct

it('blocks the character certificate over an open grave discipline case, and prints when clear', function (): void {
    $user = sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();
    $enrollment = $fixture['enrollment'];
    $student = Student::query()->findOrFail($enrollment->student_id);

    $caseId = sdocDisciplineCase($student->id, $enrollment->id, 4, $user);

    expect(fn () => app(PrintCharacterCertificate::class)->handle($student->id))
        ->toThrow(DomainException::class, 'open discipline case');

    DB::table('discipline_cases')->where('id', $caseId)->update(['status' => 'resolved']);

    $rendered = app(PrintCharacterCertificate::class)->handle($student->id);

    expect((string) $rendered->serial)->toContain('/CHAR/');
    expect($rendered->html)->toContain('good character');
    // The resolved case still shows in the honest count line.
    expect($rendered->html)->toContain('Discipline cases on record: 1');
});

it('does not block the character certificate on an open case below the severity threshold', function (): void {
    $user = sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();
    $enrollment = $fixture['enrollment'];
    $student = Student::query()->findOrFail($enrollment->student_id);

    sdocDisciplineCase($student->id, $enrollment->id, 1, $user);

    $rendered = app(PrintCharacterCertificate::class)->handle($student->id);

    expect((string) $rendered->serial)->toContain('/CHAR/');
});

it('requires the authored body for a testimonial and appends the structured facts', function (): void {
    $user = sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();
    $enrollment = $fixture['enrollment'];
    $student = Student::query()->findOrFail($enrollment->student_id);

    expect(fn () => app(PrintTestimonial::class)->handle($student->id, '   '))
        ->toThrow(DomainException::class, 'authored');

    sdocRegister($fixture['class_group_id'], $enrollment->academic_year_id, '2026-09-10', $user, []);
    sdocRegister($fixture['class_group_id'], $enrollment->academic_year_id, '2026-09-11', $user, [
        ['enrollment_id' => $enrollment->id, 'status' => 'absent'],
    ]);

    $body = 'A diligent and dependable pupil who served as class monitor.';
    $rendered = app(PrintTestimonial::class)->handle($student->id, $body);

    expect((string) $rendered->serial)->toContain('/CHAR/');
    expect($rendered->html)->toContain($body);
    expect($rendered->html)->toContain('Record of attendance and conduct');
    expect($rendered->html)->toContain('50% over 2 register days');
});

// -------------------------------------------------------------- attendance

it('refuses an attendance attestation over a period with zero registers', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();

    expect(fn () => app(PrintAttendanceCertificate::class)->handle(
        $fixture['enrollment']->student_id,
        '2026-09-01',
        '2026-09-30',
    ))->toThrow(DomainException::class, 'empty denominator');
});

it('computes the attendance rate from registers actually taken and keys the snapshot to the range', function (): void {
    $user = sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();
    $enrollment = $fixture['enrollment'];
    $student = Student::query()->findOrFail($enrollment->student_id);

    foreach (['2026-09-07', '2026-09-08', '2026-09-09'] as $date) {
        sdocRegister($fixture['class_group_id'], $enrollment->academic_year_id, $date, $user, []);
    }
    sdocRegister($fixture['class_group_id'], $enrollment->academic_year_id, '2026-09-10', $user, [
        ['enrollment_id' => $enrollment->id, 'status' => 'absent'],
    ]);

    $first = app(PrintAttendanceCertificate::class)->handle($student->id, '2026-09-01', '2026-09-30');

    expect((string) $first->serial)->toContain('/BON/');
    expect($first->html)->toContain('75%');
    expect($first->isDuplicate)->toBeFalse();

    // Same range again: the SAME issued document, as a DUPLICATA.
    $again = app(PrintAttendanceCertificate::class)->handle($student->id, '2026-09-01', '2026-09-30');
    expect($again->issuedDocumentId)->toBe($first->issuedDocumentId);
    expect($again->html)->toContain('DUPLICATA');

    // A different range is a NEW certificate with its own serial.
    $other = app(PrintAttendanceCertificate::class)->handle($student->id, '2026-09-07', '2026-09-09');
    expect($other->issuedDocumentId)->not->toBe($first->issuedDocumentId);
    expect($other->serial)->not->toBe($first->serial);
    expect($other->isDuplicate)->toBeFalse();
    expect($other->html)->toContain('100%');
});

// ------------------------------------------------------------ reachability

it('serves the bonafide PDF inline over the profile route', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();

    $response = Pest\Laravel\get('/students/'.$fixture['enrollment']->student_id.'/documents/bonafide/print');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('returns the refusal as a 422 plain-text message over the route', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment(['status' => 'withdrawn', 'left_on' => '2027-01-15']);

    $response = Pest\Laravel\get('/students/'.$fixture['enrollment']->student_id.'/documents/transfer-certificate/print');

    $response->assertStatus(422);
    expect($response->getContent())->toContain('Financial clearance');
});

it('denies the document routes to a holder of students.view without documents.print', function (): void {
    sdocUserAs(Role::Teacher);
    $fixture = sdocEnrollment();

    Pest\Laravel\get('/students/'.$fixture['enrollment']->student_id.'/documents/bonafide/print')
        ->assertForbidden();
});

// ------------------------------------------------- §2.3 forbidden strings

it('sweeps every rendered document for the §2.2 forbidden strings', function (): void {
    $user = sdocUserAs(Role::Principal, Role::Registrar);
    $fixture = sdocEnrollment();
    $enrollment = $fixture['enrollment'];
    $student = Student::query()->findOrFail($enrollment->student_id);

    DB::table('enrollments')->where('id', $enrollment->id)->update(['financial_clearance' => true]);
    sdocRegister($fixture['class_group_id'], $enrollment->academic_year_id, '2026-09-10', $user, []);

    $outputs = [
        'ADM-FORM' => app(App\Modules\Students\Actions\PrintAdmissionForm::class)->handle(null, $student->id)->html,
        'STU-INFO' => app(App\Modules\Students\Actions\PrintStudentInfoSheet::class)->handle($student->id)->html,
        'TRANSFER-CERT' => app(PrintTransferCertificate::class)->handle($student->id)->html,
        'LEAVING-CERT' => app(PrintLeavingCertificate::class)->handle($student->id)->html,
        'CHAR-CERT' => app(PrintCharacterCertificate::class)->handle($student->id)->html,
        'TESTIMONIAL' => app(PrintTestimonial::class)->handle($student->id, 'A fine pupil in every regard.')->html,
        'BONAFIDE' => app(PrintBonafideCertificate::class)->handle($student->id)->html,
        'ATTEND-CERT' => app(PrintAttendanceCertificate::class)->handle($student->id, '2026-09-01', '2026-09-30')->html,
    ];

    // §2.2/§2.3: national credentials, credential numbering, security-feature
    // legends and the denied-list signature roles. FR variants included.
    $forbidden = [
        'GCE', 'Advanced Level', 'Ordinary Level', 'Baccalauréat', 'BEPC',
        'Probatoire', 'hologram', 'hologramme', 'microtext', 'UV feature',
        'security paper', 'centre number', 'index number', 'numéro de centre',
        'Minister', 'Ministre', 'gce_board_chairman', 'directeur_bac',
    ];

    foreach ($outputs as $code => $html) {
        foreach ($forbidden as $needle) {
            expect(stripos($html, $needle))->toBeFalse("[$code] rendered the forbidden string [$needle]");
        }
        // The §2.1 letterhead is TEXT; no emblem/seal image may render.
        expect(stripos($html, 'coat_of_arms'))->toBeFalse("[$code] rendered a state emblem");
        expect(stripos($html, 'ministry_seal'))->toBeFalse("[$code] rendered a ministry seal");
    }
});
