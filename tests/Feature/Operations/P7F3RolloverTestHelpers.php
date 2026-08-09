<?php

declare(strict_types=1);

use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Fees\Actions\RecordPayment;
use App\Modules\Fees\Domain\FeeBearer;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Models\RolloverRun;
use App\Modules\Students\Actions\EnrollStudent;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\Student;
use App\Support\Money\Money;
use Database\Factories\JournalEntryFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/*
 * Shared fixtures for the P7-F3 rollover tests (steps 6-9). Every helper is
 * `function_exists`-guarded and prefixed `p7f3` so the names stay globally
 * unique across the Pest suite (HANDOVER standing rule).
 */

if (! function_exists('p7f3Operator')) {
    /**
     * A logged-in Administrator holding `rollover.run`. The Permission enum
     * case belongs to the F5 wiring agent; the VALUE is fixed by the phase-07
     * plan, so the tests create the spatie permission directly - exactly what
     * the enum-driven seeder will do once the case lands.
     */
    function p7f3Operator(): User
    {
        (new Database\Seeders\RolePermissionSeeder())->run();

        SpatiePermission::findOrCreate('rollover.run', 'web');
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(Role::Administrator->value);
        $user->givePermissionTo('rollover.run');

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p7f3Years')) {
    /**
     * A contiguous outgoing/incoming academic-year pair.
     *
     * @return array{from: int, to: int}
     */
    function p7f3Years(): array
    {
        $suffix = Str::lower(Str::random(6));

        $from = (int) DB::table('academic_years')->insertGetId([
            'code' => '2030-2031-'.$suffix,
            'name' => 'Academic Year 2030/2031',
            'starts_on' => '2030-09-01',
            'ends_on' => '2031-08-31',
            'is_current' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $to = (int) DB::table('academic_years')->insertGetId([
            'code' => '2031-2032-'.$suffix,
            'name' => 'Academic Year 2031/2032',
            'starts_on' => '2031-09-01',
            'ends_on' => '2032-08-31',
            'is_current' => false,
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['from' => $from, 'to' => $to];
    }
}

if (! function_exists('p7f3RunAt')) {
    /** A running rollover run standing exactly at `$step`. */
    function p7f3RunAt(int $fromYearId, int $toYearId, int $step, User $operator): RolloverRun
    {
        return RolloverRun::factory()->create([
            'academic_year_from_id' => $fromYearId,
            'academic_year_to_id' => $toYearId,
            'current_step' => $step,
            'step_states' => null,
            'status' => RolloverRunStatus::Running->value,
            'operator_id' => $operator->id,
            'backup_id' => null,
        ]);
    }
}

if (! function_exists('p7f3Level')) {
    function p7f3Level(SchoolSection $section, bool $examClass = false): ClassLevel
    {
        return ClassLevel::factory()->create([
            'school_section_id' => $section->id,
            'is_exam_class' => $examClass,
        ]);
    }
}

if (! function_exists('p7f3Group')) {
    function p7f3Group(int $yearId, ClassLevel $level, string $name): ClassGroup
    {
        return ClassGroup::factory()->create([
            'academic_year_id' => $yearId,
            'class_level_id' => $level->id,
            'name' => $name,
            'capacity' => 60,
        ]);
    }
}

if (! function_exists('p7f3Enroll')) {
    /** Enrolls through the Students door, so segments and constraints are real. */
    function p7f3Enroll(Student $student, int $yearId, ClassGroup $group, string $on): Enrollment
    {
        return app(EnrollStudent::class)->handle(
            studentId: $student->id,
            academicYearId: $yearId,
            classGroupId: $group->id,
            enrolledOn: $on,
        );
    }
}

if (! function_exists('p7f3Calendar')) {
    /**
     * A 2031 fiscal calendar with open periods for March (payments in the
     * outgoing year) and September (the carry, dated at the new year's
     * start).
     *
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function p7f3Calendar(): array
    {
        $calendar = (new JournalEntryFactory())->buildCalendar(Carbon::parse('2031-03-15'));

        AccountingPeriod::factory()->create([
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'period_month' => '2031-09-01',
            'starts_on' => '2031-09-01',
            'ends_on' => '2031-09-30',
            'status' => AccountingPeriodStatus::Open,
        ]);

        return $calendar;
    }
}

if (! function_exists('p7f3SavePaymentRule')) {
    /** The §15.6 MoMo shape, saved through the real SavePostingRule gate. */
    function p7f3SavePaymentRule(User $configurer): void
    {
        /** @var Journal $journal */
        $journal = Journal::query()->where('code', 'MM')->firstOrFail();

        app(SavePostingRule::class)->handle([
            'code' => 'p7f3_momo_payment',
            'event' => PostingEvent::FeePaymentReceived->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Encaissement MoMo réf. {payment.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::Literal,
                'account_code' => '552',
                'sign' => LineSign::Debit,
                'amount_expression' => 'payment.amount - payment.commission',
                'label_expression' => 'Encaissement MoMo réf. {payment.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::Literal,
                'account_code' => '6317',
                'sign' => LineSign::Debit,
                'amount_expression' => 'payment.commission',
                'label_expression' => 'Commission opérateur MoMo',
            ],
            [
                'sequence' => 3,
                'account_source' => AccountSource::Literal,
                'account_code' => '4111',
                'sign' => LineSign::Credit,
                'amount_expression' => 'payment.amount',
                'is_balancing' => true,
                'partner_source' => 'payment.partner',
                'label_expression' => '{payment.partner_label} — {payment.reference}',
            ],
        ], $configurer->toAuditActor());
    }
}

if (! function_exists('p7f3SaveCarryRule')) {
    /**
     * The 04-fees §12.6 / §15.9 reclassification shape for
     * `receivable.reclassified`: Dr 4111 / Cr 4191, BOTH lines partnered to
     * the one student - the per-student pair OHADA non-compensation demands.
     */
    function p7f3SaveCarryRule(User $configurer): void
    {
        /** @var Journal $journal */
        $journal = Journal::query()->where('code', 'OD')->firstOrFail();

        app(SavePostingRule::class)->handle([
            'code' => 'p7f3_credit_carry',
            'event' => PostingEvent::ReceivableReclassified->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Report de crédit élève {adjustment.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::Literal,
                'account_code' => '4111',
                'sign' => LineSign::Debit,
                'amount_expression' => 'adjustment.amount',
                'partner_source' => 'adjustment.partner',
                'label_expression' => 'Report de crédit — {adjustment.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::Literal,
                'account_code' => '4191',
                'sign' => LineSign::Credit,
                'amount_expression' => 'adjustment.amount',
                'is_balancing' => true,
                'partner_source' => 'adjustment.partner',
                'label_expression' => 'Avance reportée — {adjustment.reference}',
            ],
        ], $configurer->toAuditActor());
    }
}

if (! function_exists('p7f3PayCredit')) {
    /**
     * A cleared MoMo payment with NOTHING to allocate against - the whole
     * gross stays as the student's unallocated credit (04-fees C10).
     */
    function p7f3PayCredit(Student $student, int $academicYearId, int $fiscalYearId, int $amount, User $cashier): Payment
    {
        return app(RecordPayment::class)->handle(
            studentId: $student->id,
            academicYearId: $academicYearId,
            fiscalYearId: $fiscalYearId,
            method: PaymentMethod::MobileMoney,
            amount: Money::of($amount),
            payerName: 'Parent of '.$student->last_name,
            valueDate: '2031-03-15',
            actor: $cashier->toAuditActor(),
            feeAmount: Money::of(intdiv($amount, 100)),
            feeBearer: FeeBearer::School,
            reference: 'MM-'.Str::upper(Str::random(8)),
        );
    }
}

if (! function_exists('p7f3IssueInvoice')) {
    /**
     * A minimal ISSUED invoice for one student in one academic year, written
     * with DB::table (the fixture mirrors feesIssueInvoice in PaymentTest).
     *
     * @return array{invoice_id: int, line_id: int}
     */
    function p7f3IssueInvoice(Student $student, Enrollment $enrollment, int $academicYearId, int $fiscalYearId, int $amount, User $creator): array
    {
        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'invoice_no' => 'INV/2031/'.Str::upper(Str::random(8)),
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'academic_year_id' => $academicYearId,
            'fiscal_year_id' => $fiscalYearId,
            'type' => 'standard',
            'issue_date' => '2031-01-10',
            'due_date' => '2031-02-28',
            'currency' => 'XAF',
            'status' => 'issued',
            'is_migration' => false,
            'version' => 0,
            'created_by' => $creator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineId = (int) DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'line_no' => 1,
            'description' => 'Tuition',
            'description_fr' => 'Scolarité',
            'fee_category_code' => 'TUITION',
            'collection_basis' => 'own_revenue',
            'revenue_account_id' => ChartOfAccount::query()->where('code', '70611')->value('id'),
            'recognition_method' => 'on_issue',
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['invoice_id' => $invoiceId, 'line_id' => $lineId];
    }
}

if (! function_exists('p7f3Subject')) {
    /** @return int subject id */
    function p7f3Subject(string $code): int
    {
        return (int) DB::table('subjects')->insertGetId([
            'code' => $code,
            'name' => 'Subject '.$code,
            'name_fr' => 'Matière '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p7f3Allocation')) {
    /** @return int subject_allocation id */
    function p7f3Allocation(int $yearId, int $classLevelId, int $subjectId, bool $optional = false): int
    {
        return (int) DB::table('subject_allocations')->insertGetId([
            'academic_year_id' => $yearId,
            'class_level_id' => $classLevelId,
            'stream_id' => 0,
            'subject_id' => $subjectId,
            'coefficient' => 2,
            'required_components' => '[]',
            'is_optional' => $optional,
            'counts_toward_average' => true,
            'is_active' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p7f3AssignTeacher')) {
    function p7f3AssignTeacher(int $allocationId, User $teacher): void
    {
        DB::table('subject_allocation_teachers')->insert([
            'subject_allocation_id' => $allocationId,
            'user_id' => $teacher->id,
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
