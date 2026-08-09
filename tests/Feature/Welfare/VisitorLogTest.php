<?php

declare(strict_types=1);

use App\Modules\Welfare\Actions\CheckInVisitor;
use App\Modules\Welfare\Actions\CheckOutVisitor;
use App\Modules\Welfare\Domain\VisitorHostType;
use App\Modules\Welfare\Domain\VisitorPermission;
use App\Modules\Welfare\Livewire\Visitors\Index as VisitorsIndex;
use App\Modules\Welfare\Models\VisitorLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/VisitorTestHelpers.php';

uses(RefreshDatabase::class);

// ── Check-in ────────────────────────────────────────────────────────────

it('checks a visitor in and never stores the ID document reference in the clear', function () {
    $desk = p10VisitorFrontDesk();

    $idRef = 'CM-NID-104-556-789';

    $log = p10VisitorCheckIn($desk, idRef: $idRef);

    // The model decrypts on read.
    expect($log->visitor_name)->toBe('Ngwa Franklin')
        ->and($log->id_document_ref)->toBe($idRef)
        ->and($log->host_type)->toBe(VisitorHostType::Office)
        ->and($log->checked_out_at)->toBeNull()
        ->and($log->logged_by)->toBe($desk->id);

    // The RAW column holds ciphertext, not the plaintext (identity data
    // about a private individual - StudentMedicalRecord pattern).
    /** @var object{id_document_ref: string} $raw */
    $raw = DB::table('visitor_logs')->where('id', $log->getKey())->first(['id_document_ref']);

    expect($raw->id_document_ref)->not->toBe($idRef)
        ->and($raw->id_document_ref)->not->toContain('104-556');

    // And the audit trail carries NO document reference either.
    /** @var object{after: string} $audit */
    $audit = DB::table('audit_logs')
        ->where('auditable_type', VisitorLog::class)
        ->where('auditable_id', $log->getKey())
        ->first(['after']);

    expect($audit->after)->not->toContain('104-556')
        ->and($audit->after)->toContain('Ngwa Franklin')
        ->and($audit->after)->toContain('V-01');
});

it('refuses a check-in without visitor.manage', function () {
    p10VisitorUser(); // signed in, no abilities

    app(CheckInVisitor::class)->handle(
        'Walk-in Guest',
        '699000000',
        null,
        'Meeting',
        VisitorHostType::Office,
        null,
        'V-09',
        Carbon::now(),
        \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

it('rejects a check-in with blank required fields', function () {
    $desk = p10VisitorFrontDesk();

    app(CheckInVisitor::class)->handle(
        '   ',
        '',
        null,
        '',
        VisitorHostType::Office,
        null,
        '',
        Carbon::now(),
        p10VisitorActor($desk),
    );
})->throws(ValidationException::class);

it('rejects an office visit that names a host row', function () {
    $desk = p10VisitorFrontDesk();

    p10VisitorCheckIn($desk, hostType: VisitorHostType::Office, hostId: 1);
})->throws(DomainException::class, 'office visit');

it('rejects a staff host that does not exist', function () {
    $desk = p10VisitorFrontDesk();

    p10VisitorCheckIn($desk, hostType: VisitorHostType::Staff, hostId: 999_999);
})->throws(DomainException::class, 'staff host does not exist');

it('rejects a student host that does not exist', function () {
    $desk = p10VisitorFrontDesk();

    p10VisitorCheckIn($desk, hostType: VisitorHostType::Student, hostId: 999_999);
})->throws(DomainException::class, 'student host does not exist');

it('accepts a real student host', function () {
    $desk = p10VisitorFrontDesk();
    $studentId = p10VisitorStudentId();

    $log = p10VisitorCheckIn($desk, hostType: VisitorHostType::Student, hostId: $studentId);

    expect($log->host_type)->toBe(VisitorHostType::Student)
        ->and($log->host_id)->toBe($studentId);
});

// ── Badge uniqueness among the checked-in ───────────────────────────────

it('refuses a badge a visitor still on site is wearing, then frees it on check-out', function () {
    $desk = p10VisitorFrontDesk();

    $first = p10VisitorCheckIn($desk, name: 'First Guest', badge: 'V-07');

    // Same badge, still on a neck: refused.
    expect(fn () => p10VisitorCheckIn($desk, name: 'Second Guest', badge: 'V-07'))
        ->toThrow(DomainException::class, 'Badge V-07');

    // A different badge sails through.
    p10VisitorCheckIn($desk, name: 'Second Guest', badge: 'V-08');

    // Check the first visitor out - the badge is free again.
    app(CheckOutVisitor::class)->handle(
        (int) $first->getKey(),
        Carbon::now(),
        p10VisitorActor($desk),
    );

    $reissued = p10VisitorCheckIn($desk, name: 'Third Guest', badge: 'V-07');

    expect($reissued->badge_no)->toBe('V-07')
        ->and(VisitorLog::query()->onSite()->count())->toBe(2);
});

it('holds the badge invariant at the schema even when the Action is bypassed', function () {
    p10VisitorFrontDesk();

    $row = [
        'visitor_name' => 'Raw Insert',
        'phone' => '655000000',
        'purpose' => 'Bypass attempt',
        'host_type' => 'office',
        'badge_no' => 'V-77',
        'checked_in_at' => now(),
        'checked_out_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('visitor_logs')->insert($row);

    // The NULL-unique generated column rejects a second live row wearing
    // the same badge - no Action discipline required.
    expect(fn () => DB::table('visitor_logs')->insert($row))
        ->toThrow(QueryException::class);

    // But a CHECKED-OUT row may reuse the badge freely.
    $row['checked_out_at'] = now();
    DB::table('visitor_logs')->insert($row);

    expect((int) DB::table('visitor_logs')->where('badge_no', 'V-77')->count())->toBe(2);
});

// ── Check-out ───────────────────────────────────────────────────────────

it('checks a visitor out once and refuses a double check-out', function () {
    $desk = p10VisitorFrontDesk();

    $log = p10VisitorCheckIn($desk, checkedInAt: Carbon::parse('2026-08-09 08:30:00'));

    $out = app(CheckOutVisitor::class)->handle(
        (int) $log->getKey(),
        Carbon::parse('2026-08-09 10:15:00'),
        p10VisitorActor($desk),
        'GP-2026-001',
    );

    expect($out->checked_out_at?->toDateTimeString())->toBe('2026-08-09 10:15:00')
        ->and($out->gate_pass_no)->toBe('GP-2026-001')
        ->and(VisitorLog::query()->onSite()->count())->toBe(0);

    // The register is history: a second check-out is refused, not rewritten.
    expect(fn () => app(CheckOutVisitor::class)->handle(
        (int) $log->getKey(),
        Carbon::parse('2026-08-09 11:00:00'),
        p10VisitorActor($desk),
    ))->toThrow(DomainException::class, 'already checked out');
});

it('refuses a check-out dated before the check-in', function () {
    $desk = p10VisitorFrontDesk();

    $log = p10VisitorCheckIn($desk, checkedInAt: Carbon::parse('2026-08-09 08:30:00'));

    app(CheckOutVisitor::class)->handle(
        (int) $log->getKey(),
        Carbon::parse('2026-08-09 07:00:00'),
        p10VisitorActor($desk),
    );
})->throws(DomainException::class, 'before checking in');

it('refuses a check-out without visitor.manage', function () {
    $desk = p10VisitorFrontDesk();
    $log = p10VisitorCheckIn($desk);

    p10VisitorUser(); // signed in, no abilities

    app(CheckOutVisitor::class)->handle(
        (int) $log->getKey(),
        Carbon::now(),
        \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

// ── Screen ──────────────────────────────────────────────────────────────

it('renders the visitors screen with KPI cards and the register', function () {
    $desk = p10VisitorFrontDesk();

    p10VisitorCheckIn($desk, name: 'Achu Marceline', badge: 'V-03');

    Livewire::test(VisitorsIndex::class)
        ->assertSee('On Site Now')
        ->assertSee("Today's Visitors")
        ->assertSee('Checked Out Today')
        ->assertSee('Achu Marceline')
        ->assertSee('V-03');
});

it('refuses the visitors screen without visitor.manage', function () {
    p10VisitorUser(); // signed in, no abilities

    Livewire::test(VisitorsIndex::class)->assertForbidden();
});

it('checks a visitor in and out through the screen', function () {
    $desk = p10VisitorFrontDesk();
    $studentId = p10VisitorStudentId();

    /** @var string $matricule */
    $matricule = DB::table('students')->where('id', $studentId)->value('matricule');

    Livewire::test(VisitorsIndex::class)
        ->call('toggleForm')
        ->set('formName', 'Mama Regina')
        ->set('formPhone', '670112233')
        ->set('formPurpose', 'Visiting her son')
        ->set('formHostType', 'student')
        ->set('formHostRef', $matricule)
        ->set('formBadge', 'V-11')
        ->call('saveCheckIn')
        ->assertHasNoErrors();

    $log = VisitorLog::query()->where('badge_no', 'V-11')->firstOrFail();

    expect($log->visitor_name)->toBe('Mama Regina')
        ->and($log->host_type)->toBe(VisitorHostType::Student)
        ->and($log->host_id)->toBe($studentId)
        ->and($log->checked_out_at)->toBeNull();

    Livewire::test(VisitorsIndex::class)
        ->call('checkOut', $log->getKey())
        ->assertHasNoErrors();

    expect($log->refresh()->checked_out_at)->not->toBeNull();
});

it('surfaces a duplicate badge as a form error on the screen', function () {
    $desk = p10VisitorFrontDesk();

    p10VisitorCheckIn($desk, badge: 'V-05');

    Livewire::test(VisitorsIndex::class)
        ->call('toggleForm')
        ->set('formName', 'Second Wearer')
        ->set('formPhone', '691223344')
        ->set('formPurpose', 'Delivery')
        ->set('formBadge', 'V-05')
        ->call('saveCheckIn')
        ->assertHasErrors(['formBadge']);

    expect((int) DB::table('visitor_logs')->count())->toBe(1);
});
