<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Actions\TrialBalance;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Fees\Actions\RecordPayment;
use App\Modules\Fees\Actions\VoidPayment;
use App\Modules\Fees\Domain\FeeBearer;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Domain\PaymentVoidReason;
use App\Modules\Fees\Domain\PaymentVoidStatus;
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
    /** The §15.6 MoMo rule: Dr 552 net, Dr 6317 commission, Cr 4111 gross with student partner. */
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

/**
 * The end-to-end payoff of Phase 6: record the §15.6 MoMo payment, void
 * it, and prove the ledger netted to ZERO through the real Phase 4
 * machinery - ReverseJournalEntry against the payment's stored entry,
 * never a hand-built contra.
 */
it('voids a MoMo payment: reversal entry mirrors the original, ledger nets to zero, trial balance still balances', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $invoice = feesIssueInvoice($student, [['amount' => 350_000]], '2031-03-31', $cal, $cashier);

    $payment = feesRecordMomo($student, $cal, $cashier);
    expect((int) $payment->allocations()->whereNull('reversed_at')->sum('amount'))->toBe(350_000);

    // §11.5 rule 1: a DIFFERENT user with fee.void performs the void.
    $supervisor = paymentUserAs(Role::Accountant);

    $void = app(VoidPayment::class)->handle(
        $payment->id,
        PaymentVoidReason::WrongStudent,
        'Receipt issued to the wrong student account.',
        $supervisor->toAuditActor(),
    );

    expect($void->status)->toBe(PaymentVoidStatus::Confirmed)
        ->and($void->reversal_journal_entry_id)->not->toBeNull();

    $payment->refresh();
    expect($payment->isVoided())->toBeTrue()
        ->and($payment->unallocated_amount)->toBe(0);

    // Allocations are stamped reversed, never deleted (§11.4/§11.5).
    $allocations = $payment->allocations()->get();
    expect($allocations)->toHaveCount(1)
        ->and($allocations->every(fn ($a): bool => $a->reversed_at !== null))->toBeTrue();

    // The reversal is the ORIGINAL entry mirrored - debit <-> credit
    // flipped, partner preserved - and linked via reverses_entry_id.
    /** @var JournalEntry $original */
    $original = JournalEntry::query()->findOrFail($payment->journal_entry_id);
    /** @var JournalEntry $reversal */
    $reversal = JournalEntry::query()->findOrFail($void->reversal_journal_entry_id);

    expect($original->status)->toBe(JournalEntry::STATUS_REVERSED)
        ->and($reversal->reverses_entry_id)->toBe($original->id)
        ->and($reversal->is_reversal)->toBeTrue()
        ->and($reversal->total_debit)->toBe(350_000)
        ->and($reversal->total_credit)->toBe(350_000);

    $code = function (int $accountId): string {
        /** @var ChartOfAccount $account */
        $account = ChartOfAccount::query()->findOrFail($accountId);

        return $account->code;
    };

    $reversalLines = $reversal->lines()->orderBy('sequence')->get();
    expect($reversalLines)->toHaveCount(3);

    /** @var JournalEntryLine $treasury */
    $treasury = $reversalLines[0];
    /** @var JournalEntryLine $commission */
    $commission = $reversalLines[1];
    /** @var JournalEntryLine $client */
    $client = $reversalLines[2];

    expect($code($treasury->account_id))->toBe('552')
        ->and($treasury->credit)->toBe(346_500)
        ->and($code($commission->account_id))->toBe('6317')
        ->and($commission->credit)->toBe(3_500)
        ->and($code($client->account_id))->toBe('4111')
        ->and($client->debit)->toBe(350_000)
        ->and($client->partner_type?->value)->toBe('student')
        ->and($client->partner_id)->toBe($student->id);

    // Original + reversal NET TO ZERO per account - both halves stay
    // visible (§15.4), nothing silently vanished.
    foreach (['552', '6317', '4111'] as $accountCode) {
        $accountId = ChartOfAccount::query()->where('code', $accountCode)->value('id');
        $net = (int) JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS net')
            ->value('net');

        expect($net)->toBe(0);
    }

    // And the trial balance still balances.
    $rows = app(TrialBalance::class)->handle($cal['fiscal_year_id']);
    $totalDebit = (int) $rows->sum(fn (object $row): int => (int) $row->total_debit);
    $totalCredit = (int) $rows->sum(fn (object $row): int => (int) $row->total_credit);
    expect($totalDebit)->toBe($totalCredit);

    // C1: the receivable REAPPEARS - the voided payment's allocations no
    // longer count towards §5's allocated(ℓ).
    $counted = (int) DB::table('payment_allocations')
        ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
        ->where('payment_allocations.invoice_line_id', $invoice['line_ids'][0])
        ->whereNull('payment_allocations.reversed_at')
        ->whereNotExists(static function ($query): void {
            $query->selectRaw('1')
                ->from('payment_voids')
                ->whereColumn('payment_voids.payment_id', 'payments.id')
                ->where('payment_voids.status', 'confirmed');
        })
        ->sum('payment_allocations.amount');

    expect($counted)->toBe(0); // outstanding is back to the full 350 000
});

it('refuses a void by the cashier who recorded the payment - hard rule, no override (§11.5)', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordMomo($student, $cal, $cashier);

    app(VoidPayment::class)->handle(
        $payment->id,
        PaymentVoidReason::KeyingError,
        'I typed the wrong amount on this receipt.',
        $cashier->toAuditActor(),
    );
})->throws(DomainException::class, 'cannot void it');

it('voids a payment at most once', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordMomo($student, $cal, $cashier);

    $supervisor = paymentUserAs(Role::Accountant);
    $voidOnce = fn () => app(VoidPayment::class)->handle(
        $payment->id,
        PaymentVoidReason::DuplicateCapture,
        'Captured twice at the desk this morning.',
        $supervisor->toAuditActor(),
    );

    $voidOnce();
    $voidOnce();
})->throws(DomainException::class, 'already voided');

it('requires a substantive reason note', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordMomo($student, $cal, $cashier);

    $supervisor = paymentUserAs(Role::Accountant);
    app(VoidPayment::class)->handle(
        $payment->id,
        PaymentVoidReason::CashierError,
        'oops',
        $supervisor->toAuditActor(),
    );
})->throws(ValidationException::class);

it('denies voiding to a user without fee.void', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordMomo($student, $cal, $cashier);

    // A Bursar can collect but NOT void.
    $bursar = paymentUserAs(Role::Bursar);
    app(VoidPayment::class)->handle(
        $payment->id,
        PaymentVoidReason::KeyingError,
        'A perfectly well-written reason note.',
        $bursar->toAuditActor(),
    );
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('a void record itself is append-only', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordMomo($student, $cal, $cashier);

    $supervisor = paymentUserAs(Role::Accountant);
    $void = app(VoidPayment::class)->handle(
        $payment->id,
        PaymentVoidReason::FraudInvestigation,
        'Held pending the bursary investigation.',
        $supervisor->toAuditActor(),
    );

    expect(fn () => $void->forceFill(['reason_note' => 'rewritten'])->save())
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $void->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});
