<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\LogStudentActivity;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Students\Livewire\Students\Show;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\Student;
use Database\Factories\ClassGroupFactory;
use Database\Factories\FiscalYearFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/*
 * Tasks 34-37: the six student tabs that were inert. Every table and column
 * read below was reconciled against information_schema first - see
 * docs/superpowers/audits/2026-08-15-inert-controls.md, which records that
 * four of the plan's eight assumed table names did not exist.
 */

it('lists the six formerly-inert tabs among the live ones and leaves none disabled', function (): void {
    expect(Show::LIVE_TABS)->toContain(
        'overview', 'academic_records', 'attendance', 'fees', 'discipline', 'activity_log',
    );

    expect(Show::DISABLED_TABS)->toBe([])
        // Removed, not implemented: no examination-result table exists.
        ->and(Show::LIVE_TABS)->not->toContain('examinations');
});

it('selects each tab and renders its designed empty state', function (string $tab, string $emptyKey): void {
    p13coreUserAs(Role::Registrar, Role::DisciplineMaster);

    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->call('selectTab', $tab)
        ->assertSet('tab', $tab)
        ->assertOk()
        ->assertSee(__($emptyKey));
})->with([
    ['academic_records', 'opes.students_screen.academic_empty'],
    ['attendance', 'opes.students_screen.attendance_empty'],
    ['fees', 'opes.students_screen.fees_empty'],
    ['discipline', 'opes.students_screen.discipline_empty'],
    ['activity_log', 'opes.students_screen.activity_empty'],
]);

it('shows a reason on the overview, never a zero, where nothing has been recorded', function (): void {
    p13coreUserAs(Role::Registrar);

    // 09-ui 3.3: "no fee has been collected" and "the figure has not been
    // recorded" are different facts, and printing 0 for the second is how a
    // screen starts lying about a person.
    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->call('selectTab', 'overview')
        ->assertOk()
        ->assertSee(__('opes.students_screen.overview_no_attendance'))
        ->assertSee(__('opes.students_screen.overview_no_marks'))
        ->assertSee(__('opes.students_screen.overview_no_fees'))
        ->assertDontSee('0%')
        ->assertDontSee('0 FCFA');
});

it('reports a real attendance rate and lists the register rows', function (): void {
    $actorId = (int) p13coreUserAs(Role::Registrar)->getKey();

    $enrollment = Enrollment::factory()->create();
    $student = Student::query()->findOrFail($enrollment->student_id);

    // One register PER DAY: attendance_records carries a unique key on
    // (register, enrollment), so four rows for one child means four registers,
    // which is also what four school days actually look like.
    $classGroupId = ClassGroupFactory::new()->createOne()->getKey();

    foreach (['present', 'present', 'absent', 'late'] as $day => $status) {
        $registerId = DB::table('attendance_registers')->insertGetId([
            'class_group_id' => $classGroupId,
            'academic_year_id' => $enrollment->academic_year_id,
            'date' => '2026-03-0'.($day + 2),
            'session' => 'morning',
            'mode' => 'daily',
            'expected_count' => 1,
            'status' => 'submitted',
            'taken_by' => $actorId,
            'taken_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('attendance_records')->insert([
            'attendance_register_id' => $registerId,
            'enrollment_id' => $enrollment->getKey(),
            'status' => $status,
            'is_justified' => false,
            'recorded_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Three of four counted rows are present-or-late.
    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'overview')
        ->assertOk()
        ->assertSee('75.0%');

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'attendance')
        ->assertOk()
        ->assertSee(__('opes.students_screen.attendance_state_absent'))
        ->assertDontSee(__('opes.students_screen.attendance_empty'));
});

it('ignores a draft register, which is a teacher working state and not a fact about a child', function (): void {
    $actorId = (int) p13coreUserAs(Role::Registrar)->getKey();

    $enrollment = Enrollment::factory()->create();
    $student = Student::query()->findOrFail($enrollment->student_id);

    $registerId = DB::table('attendance_registers')->insertGetId([
        'class_group_id' => ClassGroupFactory::new()->createOne()->getKey(),
        'academic_year_id' => $enrollment->academic_year_id,
        'date' => '2026-03-02',
        'session' => 'morning',
        'mode' => 'daily',
        'expected_count' => 1,
        'status' => 'open',
        'taken_by' => $actorId,
        'taken_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('attendance_records')->insert([
        'attendance_register_id' => $registerId,
        'enrollment_id' => $enrollment->getKey(),
        'status' => 'absent',
        'is_justified' => false,
        'recorded_by' => $actorId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'attendance')
        ->assertOk()
        ->assertSee(__('opes.students_screen.attendance_empty'));
});

it('sums an invoice from its lines and its un-reversed allocations', function (): void {
    // `invoices` carries NO amount columns - the plan assumed total_amount and
    // paid_amount and neither exists.
    $actorId = (int) p13coreUserAs(Role::Registrar)->getKey();

    $enrollment = Enrollment::factory()->create();
    $student = Student::query()->findOrFail($enrollment->student_id);

    $fiscalYearId = (int) FiscalYearFactory::new()->createOne()->getKey();

    $invoiceId = DB::table('invoices')->insertGetId([
        'invoice_no' => 'INV-0001',
        'enrollment_id' => $enrollment->getKey(),
        'student_id' => $student->getKey(),
        'academic_year_id' => $enrollment->academic_year_id,
        'fiscal_year_id' => $fiscalYearId,
        'type' => 'standard',
        'issue_date' => '2026-01-10',
        'due_date' => '2026-02-10',
        'currency' => 'XAF',
        'status' => 'issued',
        'is_migration' => false,
        'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('invoice_lines')->insert([
        'invoice_id' => $invoiceId,
        'line_no' => 1,
        'description' => 'Tuition',
        'collection_basis' => 'own_revenue',
        'recognition_method' => 'on_issue',
        'quantity' => 1,
        'unit_amount' => 100000,
        'amount' => 100000,
        'tax_amount' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $paymentId = DB::table('payments')->insertGetId([
        'receipt_no' => 'RCT-0001',
        'student_id' => $student->getKey(),
        'enrollment_id' => $enrollment->getKey(),
        'academic_year_id' => $enrollment->academic_year_id,
        'fiscal_year_id' => $fiscalYearId,
        'payment_method' => 'cash',
        'amount' => 40000,
        'fee_amount' => 0,
        'payer_name' => 'NGONO Marie',
        'received_by' => $actorId,
        'value_date' => '2026-01-15',
        'posting_date' => '2026-01-15',
        'unallocated_amount' => 0,
        'is_migration' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('payment_allocations')->insert([
        'payment_id' => $paymentId,
        'invoice_id' => $invoiceId,
        'amount' => 40000,
        'allocated_at' => now(),
        'allocated_by' => $actorId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'fees')
        ->assertOk()
        ->assertSee('INV-0001')
        // 100 000 billed, 40 000 settled, 60 000 outstanding.
        ->assertSee('60 000');

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'overview')
        ->assertOk()
        ->assertSee('60 000 FCFA');
});

it('keeps a conduct record from an operator without the discipline permission', function (): void {
    // Said, not hidden: an operator who cannot see conduct records must not
    // conclude the child has a clean one.
    $actorId = (int) p13coreUserAs(Role::Registrar)->getKey();

    $student = Student::factory()->create();

    $categoryId = DB::table('discipline_categories')->insertGetId([
        'name' => 'Fighting', 'name_fr' => 'Bagarre', 'severity' => 3,
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('discipline_cases')->insert([
        'student_id' => $student->getKey(),
        'discipline_category_id' => $categoryId,
        'occurred_on' => '2026-02-02',
        'reported_by' => $actorId,
        'description' => 'Struck another pupil in the yard.',
        'status' => 'open',
        'visibility' => 'internal',
        'is_positive' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'discipline')
        ->assertOk()
        ->assertSee(__('opes.students_screen.discipline_forbidden'))
        ->assertDontSee('Struck another pupil in the yard.');
});

it('shows the conduct record to a discipline master', function (): void {
    // Registrar for students.view (the profile's own mount gate) plus
    // DisciplineMaster for discipline.view: the Surveillant Général does not
    // carry students.view on his own, and without it the component refuses at
    // mount rather than showing the tab.
    $actorId = (int) p13coreUserAs(Role::Registrar, Role::DisciplineMaster)->getKey();

    $student = Student::factory()->create();

    $categoryId = DB::table('discipline_categories')->insertGetId([
        'name' => 'Fighting', 'name_fr' => 'Bagarre', 'severity' => 3,
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('discipline_cases')->insert([
        'student_id' => $student->getKey(),
        'discipline_category_id' => $categoryId,
        'occurred_on' => '2026-02-02',
        'reported_by' => $actorId,
        'description' => 'Struck another pupil in the yard.',
        'status' => 'open',
        'visibility' => 'internal',
        'is_positive' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'discipline')
        ->assertOk()
        ->assertSee('Struck another pupil in the yard.')
        ->assertSee('Fighting')
        ->assertSee(__('opes.students_screen.discipline_state_open'));
});

it('lists published report cards on the academic records tab', function (): void {
    p13coreUserAs(Role::Registrar);

    $snapshot = p13coreSnapshotRow(p13coreSnapshotPayload());
    $enrollment = Enrollment::query()->findOrFail($snapshot['enrollment_id']);
    $student = Student::query()->findOrFail($enrollment->student_id);

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'academic_records')
        ->assertOk()
        ->assertDontSee(__('opes.students_screen.academic_empty'));
});

it('lists logged activity through the module Action that writes it', function (): void {
    p13coreUserAs(Role::Registrar);

    $student = Student::factory()->create();

    // Written through the module's own Action, never a raw insert: the tab
    // must render what the platform actually writes.
    app(LogStudentActivity::class)->handle(
        (int) $student->getKey(),
        StudentActivityEvent::DocumentUploaded,
        'Birth certificate attached.',
    );

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'activity_log')
        ->assertOk()
        ->assertSee('Birth certificate attached.')
        ->assertSee(__('opes.students_screen.activity_document_uploaded'));
});
