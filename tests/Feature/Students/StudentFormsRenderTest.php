<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\PrintAdmissionForm;
use App\Modules\Students\Actions\PrintStudentInfoSheet;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SdocHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §7.1 / §7.2 - the two LIVE working sheets:
 * blank/pre-filled Admission Form and the Student Information Sheet with
 * its decrypt-only-inside-the-render fields.
 */
beforeEach(function (): void {
    sdocDocumentProfile();
    sdocConfirmedFiscalIdentity();
});

it('renders a blank admission form with no series number and a Generated-on footer', function (): void {
    sdocUserAs(Role::Registrar);

    $rendered = app(PrintAdmissionForm::class)->handle();

    expect($rendered->serial)->toBeNull();       // blank copies unnumbered (§7.1)
    expect($rendered->issuedDocumentId)->toBeNull(); // live, never an IssuedDocument
    expect($rendered->html)->toContain('Admission Form');
    expect($rendered->html)->toContain('Section A');
    expect($rendered->html)->toContain('Section B');
    expect($rendered->html)->toContain('Generated on'); // §4.2 live footer
});

it('pre-fills the admission form from the student and prints the guardians', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();

    /** @var object{student_id: int} $enrollment */
    $enrollment = $fixture['enrollment'];

    $guardianId = (int) DB::table('guardians')->insertGetId([
        'guardian_no' => 'HA/GRD/2026/'.random_int(1000, 9999),
        'first_name' => 'Ngwa',
        'last_name' => 'Beltha',
        'gender' => 'female',
        'nationality' => 'CM',
        'phone' => '+237670000001',
        'occupation' => 'Trader',
        'preferred_contact_method' => 'phone',
        'language' => 'en',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('student_guardians')->insert([
        'student_id' => $enrollment->student_id,
        'guardian_id' => $guardianId,
        'relationship' => 'mother',
        'is_primary' => true,
        'has_custody' => true,
        'valid_from' => '2026-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $student = Student::query()->findOrFail($enrollment->student_id);

    $rendered = app(PrintAdmissionForm::class)->handle(null, $student->id);

    expect($rendered->html)->toContain($student->matricule);
    expect($rendered->html)->toContain($student->last_name);
    expect($rendered->html)->toContain('Ngwa Beltha');
    expect($rendered->html)->toContain('Trader');
});

it('prints the information sheet with the decrypted fields, medical rows and emergency contact', function (): void {
    $user = sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();

    /** @var object{student_id: int, id: int} $enrollment */
    $enrollment = $fixture['enrollment'];

    $student = Student::query()->findOrFail($enrollment->student_id);
    $student->blood_group = 'O+';
    $student->genotype = 'AS';
    $student->religion = 'Presbyterian';
    $student->save();

    // Ciphertext at rest - the plaintext must not exist in the column.
    $raw = DB::table('students')->where('id', $student->id)->value('blood_group');
    expect(is_string($raw) ? $raw : '')->not->toContain('O+');

    DB::table('student_medical_records')->insert([
        'student_id' => $student->id,
        'condition_type' => 'allergy',
        'summary' => 'Peanut allergy - carries antihistamine',
        'detail' => null,
        'is_emergency_relevant' => true,
        'severity' => 'high',
        'recorded_by' => $user->getKey(),
        'recorded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $guardianId = (int) DB::table('guardians')->insertGetId([
        'guardian_no' => 'HA/GRD/2026/'.random_int(1000, 9999),
        'first_name' => 'Tabi',
        'last_name' => 'Emmanuel',
        'gender' => 'male',
        'nationality' => 'CM',
        'phone' => '+237699000002',
        'preferred_contact_method' => 'phone',
        'language' => 'en',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('student_guardians')->insert([
        'student_id' => $student->id,
        'guardian_id' => $guardianId,
        'relationship' => 'father',
        'is_primary' => true,
        'has_custody' => true,
        'is_emergency_contact' => true,
        'valid_from' => '2026-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rendered = app(PrintStudentInfoSheet::class)->handle($student->id);

    expect($rendered->serial)->toBeNull(); // §7.2: no series
    expect($rendered->html)->toContain('O+');
    expect($rendered->html)->toContain('AS');
    expect($rendered->html)->toContain('Presbyterian');
    expect($rendered->html)->toContain('Peanut allergy');
    expect($rendered->html)->toContain('Tabi Emmanuel');
    expect($rendered->html)->toContain($fixture['class_group_name']);

    // §7.2: printing IS the audited event, with the field list recorded and
    // never a value.
    $label = DB::table('document_print_logs')
        ->where('subject_type', 'Student')
        ->where('subject_id', $student->id)
        ->value('subject_label_at_time');
    expect((string) $label)->toContain('religion,blood_group,genotype,national_id');
    expect((string) $label)->not->toContain('O+');
});

it('re-renders the live sheet with current data after a change, as a working view', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();

    /** @var object{student_id: int} $enrollment */
    $enrollment = $fixture['enrollment'];
    $student = Student::query()->findOrFail($enrollment->student_id);

    $first = app(PrintStudentInfoSheet::class)->handle($student->id);
    expect($first->html)->toContain($student->last_name);

    $student->last_name = 'Renamed-Afterwards';
    $student->save();

    $second = app(PrintStudentInfoSheet::class)->handle($student->id);
    expect($second->html)->toContain('Renamed-Afterwards');   // §4.2 live
    expect($second->isDuplicate)->toBeFalse();                 // never DUPLICATA
    expect($second->html)->not->toContain('DUPLICATA');
});

it('refuses a non-existent student id', function (): void {
    sdocUserAs(Role::Registrar);

    expect(fn () => app(PrintStudentInfoSheet::class)->handle(999_999))
        ->toThrow(DomainException::class, 'does not exist');
});
