<?php

declare(strict_types=1);

use App\Modules\Students\Actions\EmergencyMedicalSummary;
use App\Modules\Students\Models\StudentMedicalRecord;
use App\Modules\Welfare\Actions\CloseReferral;
use App\Modules\Welfare\Actions\MedicalDashboardStats;
use App\Modules\Welfare\Actions\RecordConsultation;
use App\Modules\Welfare\Actions\RecordReferral;
use App\Modules\Welfare\Domain\ConsultationOutcome;
use App\Modules\Welfare\Domain\ConsultationSeverity;
use App\Modules\Welfare\Domain\MedicalPermission;
use App\Modules\Welfare\Livewire\Medical\Index as MedicalIndex;
use App\Modules\Welfare\Models\MedicalConsultation;
use App\Modules\Welfare\Models\MedicalReferral;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/MedicalTestHelpers.php';

uses(RefreshDatabase::class);

// ── Recording ───────────────────────────────────────────────────────────

it('records a consultation and never stores clinical text in the clear', function () {
    $nurse = p10MedicalNurse();
    [$studentId, $enrollmentId] = p10MedicalEnrolledStudent();

    $complaint = 'Severe abdominal pain after lunch';

    $consultation = p10MedicalConsultation(
        $nurse, $studentId, $enrollmentId,
        complaint: $complaint,
        severity: ConsultationSeverity::Moderate,
        outcome: ConsultationOutcome::SentHome,
    );

    // The model decrypts on read.
    expect($consultation->presenting_complaint)->toBe($complaint)
        ->and($consultation->diagnosis)->toBe('Suspected malaria')
        ->and($consultation->treatment)->toBe('Paracetamol 500mg, observation')
        ->and($consultation->severity)->toBe(ConsultationSeverity::Moderate)
        ->and($consultation->outcome)->toBe(ConsultationOutcome::SentHome)
        ->and($consultation->enrollment_id)->toBe($enrollmentId);

    // The plan's core security assertion: the RAW columns hold ciphertext,
    // not the plaintext (00-core 9.5 - health data about a minor).
    /** @var object{presenting_complaint: string, diagnosis: string, treatment: string} $raw */
    $raw = DB::table('medical_consultations')
        ->where('id', $consultation->getKey())
        ->first(['presenting_complaint', 'diagnosis', 'treatment']);

    expect($raw->presenting_complaint)->not->toBe($complaint)
        ->and($raw->presenting_complaint)->not->toContain('abdominal')
        ->and($raw->diagnosis)->not->toContain('malaria')
        ->and($raw->treatment)->not->toContain('Paracetamol');

    // And the audit trail carries NO clinical narrative either.
    /** @var object{after: string} $audit */
    $audit = DB::table('audit_logs')
        ->where('auditable_type', MedicalConsultation::class)
        ->where('auditable_id', $consultation->getKey())
        ->first(['after']);

    expect($audit->after)->not->toContain('abdominal')
        ->and($audit->after)->not->toContain('malaria')
        ->and($audit->after)->toContain('sent_home');
});

it('refuses to record a consultation without medical.manage', function () {
    p10MedicalUser(MedicalPermission::VIEW); // view-only: not enough

    app(RecordConsultation::class)->handle(
        p10MedicalStudentId(),
        null,
        Carbon::now(),
        'Sore throat',
        null,
        null,
        ConsultationSeverity::Low,
        ConsultationOutcome::ReturnedToClass,
        \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

it('rejects an unknown student', function () {
    $nurse = p10MedicalNurse();

    app(RecordConsultation::class)->handle(
        999_999,
        null,
        Carbon::now(),
        'Sore throat',
        null,
        null,
        ConsultationSeverity::Low,
        ConsultationOutcome::ReturnedToClass,
        p10MedicalActor($nurse),
    );
})->throws(DomainException::class, 'does not exist');

it('rejects an enrollment that belongs to another student', function () {
    $nurse = p10MedicalNurse();
    [, $otherEnrollmentId] = p10MedicalEnrolledStudent();

    app(RecordConsultation::class)->handle(
        p10MedicalStudentId(),
        $otherEnrollmentId,
        Carbon::now(),
        'Sore throat',
        null,
        null,
        ConsultationSeverity::Low,
        ConsultationOutcome::ReturnedToClass,
        p10MedicalActor($nurse),
    );
})->throws(DomainException::class, 'does not belong');

// ── Referrals ───────────────────────────────────────────────────────────

it('records a referral, encrypts the reason and flips the consultation outcome', function () {
    $nurse = p10MedicalNurse();
    $consultation = p10MedicalConsultation($nurse, p10MedicalStudentId());

    expect($consultation->outcome)->toBe(ConsultationOutcome::ReturnedToClass);

    $reason = 'Suspected appendicitis requiring surgical review';

    $referral = app(RecordReferral::class)->handle(
        (int) $consultation->getKey(),
        'Regional Hospital Bamenda',
        $reason,
        Carbon::parse('2026-09-14'),
        p10MedicalActor($nurse),
    );

    expect($referral->reason)->toBe($reason)
        ->and($referral->followed_up_at)->toBeNull()
        ->and($consultation->refresh()->outcome)->toBe(ConsultationOutcome::Referred);

    // Raw column is ciphertext.
    /** @var object{reason: string} $raw */
    $raw = DB::table('medical_referrals')->where('id', $referral->getKey())->first(['reason']);

    expect($raw->reason)->not->toBe($reason)
        ->and($raw->reason)->not->toContain('appendicitis');
});

it('refuses a referral without medical.manage', function () {
    $nurse = p10MedicalNurse();
    $consultation = p10MedicalConsultation($nurse, p10MedicalStudentId());

    p10MedicalUser(MedicalPermission::VIEW);

    app(RecordReferral::class)->handle(
        (int) $consultation->getKey(),
        'Regional Hospital',
        'Needs an X-ray',
        Carbon::parse('2026-09-14'),
        \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

it('closes a referral once and refuses a double close', function () {
    $nurse = p10MedicalNurse();
    $consultation = p10MedicalConsultation($nurse, p10MedicalStudentId());

    $referral = app(RecordReferral::class)->handle(
        (int) $consultation->getKey(),
        'Regional Hospital Bamenda',
        'Fracture suspected',
        Carbon::parse('2026-09-14'),
        p10MedicalActor($nurse),
    );

    $closed = app(CloseReferral::class)->handle(
        (int) $referral->getKey(),
        Carbon::parse('2026-09-20 10:00:00'),
        'Cast fitted; review in six weeks',
        p10MedicalActor($nurse),
    );

    expect($closed->followed_up_at?->toDateString())->toBe('2026-09-20')
        ->and($closed->notes)->toBe('Cast fitted; review in six weeks')
        ->and(MedicalReferral::query()->open()->count())->toBe(0);

    expect(fn () => app(CloseReferral::class)->handle(
        (int) $referral->getKey(),
        Carbon::parse('2026-09-21'),
        null,
        p10MedicalActor($nurse),
    ))->toThrow(DomainException::class, 'already closed');
});

it('refuses a follow-up dated before the referral was made', function () {
    $nurse = p10MedicalNurse();
    $consultation = p10MedicalConsultation($nurse, p10MedicalStudentId());

    $referral = app(RecordReferral::class)->handle(
        (int) $consultation->getKey(),
        'Regional Hospital Bamenda',
        'Fracture suspected',
        Carbon::parse('2026-09-14'),
        p10MedicalActor($nurse),
    );

    app(CloseReferral::class)->handle(
        (int) $referral->getKey(),
        Carbon::parse('2026-09-10'),
        null,
        p10MedicalActor($nurse),
    );
})->throws(DomainException::class, 'before it was made');

// ── Dashboard stats ─────────────────────────────────────────────────────

it('computes the dashboard stats the 09-ui Medical cards need', function () {
    $nurse = p10MedicalNurse();
    $studentId = p10MedicalStudentId();

    // Two visits today (one high-severity admission), one last week, one old.
    p10MedicalConsultation($nurse, $studentId, visitedAt: Carbon::now());
    $admitted = p10MedicalConsultation(
        $nurse, $studentId,
        severity: ConsultationSeverity::High,
        outcome: ConsultationOutcome::Admitted,
        visitedAt: Carbon::now(),
    );
    p10MedicalConsultation(
        $nurse, $studentId,
        outcome: ConsultationOutcome::SentHome,
        visitedAt: Carbon::now()->subDays(3),
    );
    p10MedicalConsultation($nurse, $studentId, visitedAt: Carbon::now()->subDays(60));

    // One open referral.
    app(RecordReferral::class)->handle(
        (int) $admitted->getKey(),
        'Regional Hospital Bamenda',
        'Continued observation needed',
        Carbon::today(),
        p10MedicalActor($nurse),
    );

    // One chronic-condition record in Students (cross-module COUNT read).
    StudentMedicalRecord::query()->create([
        'student_id' => $studentId,
        'condition_type' => 'allergy',
        'summary' => 'Peanut allergy',
        'detail' => 'Carries epipen in school bag',
        'is_emergency_relevant' => true,
        'severity' => 'high',
        'recorded_at' => now(),
    ]);

    $stats = app(MedicalDashboardStats::class)->handle();

    expect($stats['today_visits'])->toBe(2)
        // admitted (today) + referred (today, flipped) is the same row;
        // sent_home 3 days ago also counts. returned_to_class rows do not.
        ->and($stats['active_treatments'])->toBe(2)
        ->and($stats['medical_records'])->toBe(1)
        ->and($stats['open_referrals'])->toBe(1)
        ->and($stats['referrals_total'])->toBe(1)
        ->and($stats['severity_breakdown']['high'])->toBe(1)
        ->and($stats['severity_breakdown']['low'])->toBe(2);
});

it('refuses the dashboard stats without medical.view', function () {
    p10MedicalUser(); // signed in, no abilities

    app(MedicalDashboardStats::class)->handle();
})->throws(AuthorizationException::class);

// ── Students door: EmergencyMedicalSummary ──────────────────────────────

it('surfaces decrypted emergency-relevant conditions through the Students door', function () {
    p10MedicalUser(MedicalPermission::VIEW);
    $studentId = p10MedicalStudentId();

    StudentMedicalRecord::query()->create([
        'student_id' => $studentId,
        'condition_type' => 'allergy',
        'summary' => 'Peanut allergy',
        'detail' => 'Anaphylaxis risk - epipen in front pocket of school bag',
        'is_emergency_relevant' => true,
        'severity' => 'high',
        'recorded_at' => now(),
    ]);

    // Not emergency-relevant: must NOT surface through this door.
    StudentMedicalRecord::query()->create([
        'student_id' => $studentId,
        'condition_type' => 'immunisation',
        'summary' => 'Tetanus booster 2025',
        'detail' => 'Routine immunisation record',
        'is_emergency_relevant' => false,
        'severity' => 'low',
        'recorded_at' => now(),
    ]);

    $summary = app(EmergencyMedicalSummary::class)->handle($studentId);

    expect($summary['student']['id'])->toBe($studentId)
        ->and($summary['student']['matricule'])->toStartWith('OS-26-')
        ->and($summary['records'])->toHaveCount(1)
        ->and($summary['records'][0]['condition_type'])->toBe('allergy')
        // Decrypted in the door - while the raw column stays ciphertext.
        ->and($summary['records'][0]['detail'])->toContain('epipen');

    /** @var object{detail: string} $raw */
    $raw = DB::table('student_medical_records')
        ->where('student_id', $studentId)
        ->where('is_emergency_relevant', true)
        ->first(['detail']);

    expect($raw->detail)->not->toContain('epipen');
});

it('refuses the emergency summary without medical.view', function () {
    p10MedicalUser(); // signed in, no abilities

    app(EmergencyMedicalSummary::class)->handle(p10MedicalStudentId());
})->throws(AuthorizationException::class);

// ── Screen ──────────────────────────────────────────────────────────────

it('renders the medical screen with KPI cards and the decrypted log', function () {
    $nurse = p10MedicalNurse();
    $studentId = p10MedicalStudentId();

    p10MedicalConsultation(
        $nurse, $studentId,
        complaint: 'Twisted ankle during sports',
        severity: ConsultationSeverity::Moderate,
    );

    Livewire::test(MedicalIndex::class)
        ->assertSee("Today's Visits")
        ->assertSee('Active Treatments')
        ->assertSee('Medical Records')
        ->assertSee('Open Referrals')
        ->assertSee('Twisted ankle during sports')
        ->assertSee('Medic');
});

it('refuses the medical screen without medical.view', function () {
    p10MedicalUser(); // signed in, no abilities

    Livewire::test(MedicalIndex::class)->assertForbidden();
});

it('records a consultation through the screen form', function () {
    $nurse = p10MedicalNurse();
    $studentId = p10MedicalStudentId();

    /** @var string $matricule */
    $matricule = DB::table('students')->where('id', $studentId)->value('matricule');

    Livewire::test(MedicalIndex::class)
        ->call('toggleForm')
        ->set('formMatricule', $matricule)
        ->set('formComplaint', 'Bee sting on the playground')
        ->set('formSeverity', 'moderate')
        ->set('formOutcome', 'returned_to_class')
        ->call('saveConsultation')
        ->assertHasNoErrors();

    $consultation = MedicalConsultation::query()
        ->where('student_id', $studentId)
        ->firstOrFail();

    expect($consultation->presenting_complaint)->toBe('Bee sting on the playground')
        ->and($consultation->severity)->toBe(ConsultationSeverity::Moderate);
});
