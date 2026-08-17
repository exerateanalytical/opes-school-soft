<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\PrintLeaveApplication;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/SdocHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §11.3 - LEAVE-APP renders as a live document
 * through RenderDocument (10-documents §4.8 is the only path to a PDF).
 */
beforeEach(function (): void {
    sdocDocumentProfile();
    sdocConfirmedFiscalIdentity();
});

it('renders a blank leave application form with no student', function (): void {
    sdocUserAs(Role::Registrar);

    $rendered = app(PrintLeaveApplication::class)->handle();

    expect($rendered->bytes)->toStartWith('%PDF-');
    expect($rendered->serial)->toBeNull();
    expect($rendered->html)->toContain('Leave Application');
});

it('pre-fills the student name and class from a live enrollment', function (): void {
    sdocUserAs(Role::Registrar);
    $fixture = sdocEnrollment();
    $student = Student::query()->findOrFail($fixture['enrollment']->student_id);

    $rendered = app(PrintLeaveApplication::class)->handle(
        studentId: $student->id,
        reason: 'Family travel',
        fromDate: '2026-09-01',
        toDate: '2026-09-05',
    );

    expect($rendered->bytes)->toStartWith('%PDF-');
    expect($rendered->html)->toContain($student->last_name);
    expect($rendered->html)->toContain($fixture['class_group_name']);
    expect($rendered->html)->toContain('Family travel');
});
