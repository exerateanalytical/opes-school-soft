<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Fees\Actions\RecordPayment;
use App\Modules\Fees\Domain\FeeBearer;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\Student;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('paymentUserAs')) {
    /** A logged-in user holding the union of the given roles' permissions. */
    function paymentUserAs(Role ...$roles): User
    {
        (new Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role->value);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('feesSaveMomoPaymentRule')) {
    /**
     * The §15.6 / 02-accounting §11.3 MoMo rule, saved through the real
     * SavePostingRule gate: Dr 552 net, Dr 6317 commission, Cr 4111 gross
     * with the student partner.
     */
    function feesSaveMomoPaymentRule(User $accountant): void
    {
        /** @var Journal $journal */
        $journal = Journal::query()->where('code', 'MM')->firstOrFail();

        app(SavePostingRule::class)->handle([
            'code' => 'f3_momo_fee_payment',
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
        ], $accountant->toAuditActor());
    }
}

if (! function_exists('feesIssueInvoice')) {
    /**
     * A minimal ISSUED invoice with lines, written with DB::table because
     * the invoice models belong to a parallel Phase 6 work package.
     *
     * @param  list<array{amount: int, tax_amount?: int}>  $lines
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $cal
     * @return array{invoice_id: int, line_ids: list<int>}
     */
    function feesIssueInvoice(Student $student, array $lines, string $dueDate, array $cal, User $creator): array
    {
        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::factory()->create(['student_id' => $student->id]);

        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'invoice_no' => 'INV/2031/'.str_pad((string) random_int(1, 999_999), 6, '0', STR_PAD_LEFT),
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'academic_year_id' => $cal['academic_year_id'],
            'fiscal_year_id' => $cal['fiscal_year_id'],
            'type' => 'standard',
            'issue_date' => '2031-03-01',
            'due_date' => $dueDate,
            'currency' => 'XAF',
            'status' => 'issued',
            'is_migration' => false,
            'version' => 0,
            'created_by' => $creator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineIds = [];

        foreach ($lines as $index => $line) {
            $lineIds[] = (int) DB::table('invoice_lines')->insertGetId([
                'invoice_id' => $invoiceId,
                'line_no' => $index + 1,
                'description' => 'Line '.($index + 1),
                'description_fr' => 'Ligne '.($index + 1),
                'fee_category_code' => 'TUITION',
                'collection_basis' => 'own_revenue',
                'revenue_account_id' => ChartOfAccount::query()->where('code', '70611')->value('id'),
                'recognition_method' => 'on_issue',
                'quantity' => 1,
                'unit_amount' => $line['amount'],
                'amount' => $line['amount'],
                'tax_amount' => $line['tax_amount'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return ['invoice_id' => $invoiceId, 'line_ids' => $lineIds];
    }
}

if (! function_exists('feesRecordMomo')) {
    /**
     * Records the §15.6 payment: 350 000 gross, 1% commission borne by the school.
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $cal
     * @param  list<array{invoice_line_id: int, amount: int}>|null  $targets
     */
    function feesRecordMomo(Student $student, array $cal, User $cashier, ?array $targets = null): Payment
    {
        return app(RecordPayment::class)->handle(
            studentId: $student->id,
            academicYearId: $cal['academic_year_id'],
            fiscalYearId: $cal['fiscal_year_id'],
            method: PaymentMethod::MobileMoney,
            amount: Money::of(350_000),
            payerName: 'Doe, Jane (mother)',
            valueDate: '2031-03-15',
            actor: $cashier->toAuditActor(),
            feeAmount: Money::of(3_500),
            feeBearer: FeeBearer::School,
            reference: 'MM-88421',
            targets: $targets,
        );
    }
}

it('records a MoMo payment with commission and posts the §15.6 shape: 552 net, 6317 commission, 4111 gross with student partner', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();

    $payment = feesRecordMomo($student, $cal, $cashier);

    expect($payment->receipt_no)->toMatch('/^RCPT\/2031\/\d{6}$/')
        ->and($payment->amount)->toBe(350_000)
        ->and($payment->fee_amount)->toBe(3_500)
        ->and($payment->journal_entry_id)->not->toBeNull();

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($payment->journal_entry_id);
    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->total_debit)->toBe(350_000)
        ->and($entry->total_credit)->toBe(350_000);

    $lines = $entry->lines()->orderBy('sequence')->get();
    expect($lines)->toHaveCount(3);

    $code = function (JournalEntryLine $line): string {
        /** @var ChartOfAccount $account */
        $account = ChartOfAccount::query()->findOrFail($line->account_id);

        return $account->code;
    };

    /** @var JournalEntryLine $treasury */
    $treasury = $lines[0];
    /** @var JournalEntryLine $commission */
    $commission = $lines[1];
    /** @var JournalEntryLine $client */
    $client = $lines[2];

    // Dr 552 Téléphone Portable — the NET the operator will settle (§15.6).
    expect($code($treasury))->toBe('552')
        ->and($treasury->debit)->toBe(346_500)
        // Dr 6317 — the commission the school bore.
        ->and($code($commission))->toBe('6317')
        ->and($commission->debit)->toBe(3_500)
        // Cr 4111 — the GROSS, with the student partner for the aux ledger.
        ->and($code($client))->toBe('4111')
        ->and($client->credit)->toBe(350_000)
        ->and($client->partner_type?->value)->toBe('student')
        ->and($client->partner_id)->toBe($student->id);
});

it('auto-allocates oldest invoice first and holds the surplus as unallocated credit (C10)', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();

    // Older invoice (due first): 200 000. Newer: 100 000. Payment: 350 000.
    $older = feesIssueInvoice($student, [['amount' => 200_000]], '2031-01-31', $cal, $cashier);
    $newer = feesIssueInvoice($student, [['amount' => 100_000]], '2031-04-30', $cal, $cashier);

    $payment = feesRecordMomo($student, $cal, $cashier);

    $allocations = $payment->allocations()->orderBy('id')->get();

    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]?->invoice_line_id)->toBe($older['line_ids'][0])
        ->and($allocations[0]?->amount)->toBe(200_000)
        ->and($allocations[1]?->invoice_line_id)->toBe($newer['line_ids'][0])
        ->and($allocations[1]?->amount)->toBe(100_000)
        // The 50 000 surplus is student credit, not an error (C10/C9).
        ->and($payment->unallocated_amount)->toBe(50_000);
});

it('refuses an explicit allocation past a line outstanding instead of silently truncating (§5.3/§11.2)', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $invoice = feesIssueInvoice($student, [['amount' => 100_000]], '2031-03-31', $cal, $cashier);

    feesRecordMomo($student, $cal, $cashier, [
        ['invoice_line_id' => $invoice['line_ids'][0], 'amount' => 150_000],
    ]);
})->throws(DomainException::class, 'would exceed its outstanding');

it('is idempotent on the idempotency key - a double-clicked Collect returns the same payment', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();

    $record = fn (): Payment => app(RecordPayment::class)->handle(
        studentId: $student->id,
        academicYearId: $cal['academic_year_id'],
        fiscalYearId: $cal['fiscal_year_id'],
        method: PaymentMethod::Cash,
        amount: Money::of(50_000),
        payerName: 'Uncle Ben',
        valueDate: '2031-03-15',
        actor: $cashier->toAuditActor(),
        idempotencyKey: 'collect-abc-123',
    );

    $first = $record();
    $second = $record();

    expect($second->id)->toBe($first->id)
        ->and(Payment::query()->count())->toBe(1);
});

it('requires a transaction reference for mobile money and bank, not for cash (§2.4)', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();

    app(RecordPayment::class)->handle(
        studentId: $student->id,
        academicYearId: $cal['academic_year_id'],
        fiscalYearId: $cal['fiscal_year_id'],
        method: PaymentMethod::MobileMoney,
        amount: Money::of(50_000),
        payerName: 'Doe, Jane',
        valueDate: '2031-03-15',
        actor: $cashier->toAuditActor(),
    );
})->throws(ValidationException::class);

it('freezes every financial column after insert - corrections are void + re-record, never an edit (A3)', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordMomo($student, $cal, $cashier);

    expect(fn () => $payment->forceFill(['amount' => 1])->save())
        ->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $payment->forceFill(['receipt_no' => 'RCPT/2031/999999'])->save())
        ->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $payment->forceFill(['value_date' => '2031-03-16'])->save())
        ->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $payment->forceFill(['journal_entry_id' => 999])->save())
        ->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $payment->delete())
        ->toThrow(RuntimeException::class, 'cannot be deleted');

    // The sanctioned writes still work: the derived cache and notes.
    $payment->refresh();
    $payment->notes = 'annotated';
    $payment->save();

    expect($payment->refresh()->notes)->toBe('annotated');
});

it('denies recording to a user without fee.collect', function (): void {
    paymentUserAs(Role::Teacher);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    /** @var User $teacher */
    $teacher = User::query()->firstOrFail();

    app(RecordPayment::class)->handle(
        studentId: $student->id,
        academicYearId: $cal['academic_year_id'],
        fiscalYearId: $cal['fiscal_year_id'],
        method: PaymentMethod::Cash,
        amount: Money::of(10_000),
        payerName: 'Someone',
        valueDate: '2031-03-15',
        actor: $teacher->toAuditActor(),
    );
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
