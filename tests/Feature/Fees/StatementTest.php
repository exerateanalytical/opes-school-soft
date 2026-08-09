<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Fees\Actions\StudentStatement;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('statementUserAs')) {
    function statementUserAs(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('fee.view', 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo('fee.view');
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('statementScaffold')) {
    /**
     * Enrollment + fiscal year; prerequisite rows via factories that are
     * already self-sufficient (EnrollmentFactory inserts its own student,
     * academic year, level and section with DB::table).
     *
     * @return array{enrollment: Enrollment, fiscalYearId: int, user: User}
     */
    function statementScaffold(): array
    {
        $user = statementUserAs();
        actingAs($user);

        return [
            'enrollment' => Enrollment::factory()->create(),
            'fiscalYearId' => (int) FiscalYear::factory()->open()->create()->getKey(),
            'user' => $user,
        ];
    }
}

if (! function_exists('statementInvoice')) {
    /**
     * An ISSUED invoice with one line, via DB::table - invoice issuing is a
     * concurrent work package; the statement reads rows, not Actions.
     *
     * @return array{invoiceId: int, lineId: int}
     */
    function statementInvoice(
        Enrollment $enrollment,
        int $fiscalYearId,
        int $amount,
        string $issueDate,
        string $status = 'issued',
    ): array {
        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'invoice_no' => $status === 'draft' ? null : 'INV/2026/'.Str::upper(Str::random(8)),
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'academic_year_id' => $enrollment->academic_year_id,
            'fiscal_year_id' => $fiscalYearId,
            'type' => 'standard',
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineId = (int) DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'line_no' => 1,
            'description' => 'Tuition Fee',
            'collection_basis' => 'own_revenue',
            'revenue_account_id' => ChartOfAccount::factory()->create()->id,
            'recognition_method' => 'on_issue',
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['invoiceId' => $invoiceId, 'lineId' => $lineId];
    }
}

if (! function_exists('statementPayment')) {
    /**
     * A cleared payment, optionally allocated to a line.
     */
    function statementPayment(
        Enrollment $enrollment,
        int $fiscalYearId,
        int $amount,
        string $valueDate,
        User $receivedBy,
        ?int $invoiceId = null,
        ?int $lineId = null,
    ): int {
        $paymentId = (int) DB::table('payments')->insertGetId([
            'receipt_no' => 'RCPT/2026/'.Str::upper(Str::random(8)),
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'academic_year_id' => $enrollment->academic_year_id,
            'fiscal_year_id' => $fiscalYearId,
            'payment_method' => 'cash',
            'amount' => $amount,
            'payer_name' => 'Test Payer',
            'value_date' => $valueDate,
            'posting_date' => $valueDate,
            'clearing_state' => 'cleared',
            'unallocated_amount' => $invoiceId === null ? $amount : 0,
            'received_by' => $receivedBy->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($invoiceId !== null && $lineId !== null) {
            DB::table('payment_allocations')->insert([
                'payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
                'invoice_line_id' => $lineId,
                'amount' => $amount,
                'allocated_at' => now(),
                'allocated_by' => $receivedBy->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $paymentId;
    }
}

it('refuses without fee.view', function () {
    $user = statementUserAs(withPermission: false);
    actingAs($user);

    app(StudentStatement::class)->handle(1);
})->throws(AuthorizationException::class);

it('lists invoice and payment chronologically with a running balance', function () {
    $s = statementScaffold();
    $invoice = statementInvoice($s['enrollment'], $s['fiscalYearId'], 350_000, '2026-09-10');
    statementPayment($s['enrollment'], $s['fiscalYearId'], 240_000, '2026-10-01', $s['user'], $invoice['invoiceId'], $invoice['lineId']);

    $rows = app(StudentStatement::class)->handle((int) $s['enrollment']->id, '2026-12-31');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]?->debit)->toBe(350_000)
        ->and($rows[0]?->balance)->toBe(350_000)
        ->and($rows[1]?->credit)->toBe(240_000)
        ->and($rows[1]?->balance)->toBe(110_000);
});

it('excludes draft and cancelled invoices from the statement', function () {
    $s = statementScaffold();
    statementInvoice($s['enrollment'], $s['fiscalYearId'], 100_000, '2026-09-10', status: 'draft');
    statementInvoice($s['enrollment'], $s['fiscalYearId'], 200_000, '2026-09-11', status: 'cancelled');
    statementInvoice($s['enrollment'], $s['fiscalYearId'], 350_000, '2026-09-12');

    $rows = app(StudentStatement::class)->handle((int) $s['enrollment']->id, '2026-12-31');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]?->debit)->toBe(350_000);
});

it('shows a voided payment as a credit AND a matching debit, netting to zero', function () {
    // §5.2's release-blocking test, statement form: a payment must never
    // silently disappear - a parent who sees one vanish will assume theft.
    $s = statementScaffold();
    $invoice = statementInvoice($s['enrollment'], $s['fiscalYearId'], 350_000, '2026-09-10');
    $paymentId = statementPayment($s['enrollment'], $s['fiscalYearId'], 350_000, '2026-10-01', $s['user'], $invoice['invoiceId'], $invoice['lineId']);

    DB::table('payment_voids')->insert([
        'payment_id' => $paymentId,
        'reason_type' => 'wrong_student',
        'reason_note' => 'Receipt issued to the wrong student.',
        'voided_by' => $s['user']->getKey(),
        'voided_at' => '2026-11-05 10:00:00',
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rows = app(StudentStatement::class)->handle((int) $s['enrollment']->id, '2026-12-31');

    expect($rows)->toHaveCount(3)
        ->and($rows[1]?->credit)->toBe(350_000)
        ->and($rows[2]?->debit)->toBe(350_000)
        ->and($rows[2]?->description)->toBe('Payment voided')
        // C1: the void restores the full balance.
        ->and($rows[2]?->balance)->toBe(350_000);
});

it('shows a bounced cheque as a debit restoring the balance', function () {
    $s = statementScaffold();
    $invoice = statementInvoice($s['enrollment'], $s['fiscalYearId'], 350_000, '2026-09-10');
    $paymentId = statementPayment($s['enrollment'], $s['fiscalYearId'], 350_000, '2026-10-01', $s['user'], $invoice['invoiceId'], $invoice['lineId']);

    DB::table('payments')->where('id', $paymentId)->update([
        'clearing_state' => 'bounced',
        'bounced_on' => '2026-10-20',
        'bounce_reason' => 'Insufficient funds',
    ]);

    $rows = app(StudentStatement::class)->handle((int) $s['enrollment']->id, '2026-12-31');

    expect($rows)->toHaveCount(3)
        ->and($rows[2]?->description)->toBe('Cheque bounced')
        ->and($rows[2]?->debit)->toBe(350_000)
        ->and($rows[2]?->balance)->toBe(350_000);
});

it('shows adjustments signed and credit notes as credits', function () {
    $s = statementScaffold();
    $invoice = statementInvoice($s['enrollment'], $s['fiscalYearId'], 350_000, '2026-09-10');

    $account = ChartOfAccount::factory()->create();

    // Positive adjustment relieves; negative surcharge increases (§5, §19).
    foreach ([['ADJ-1', 50_000, '2026-10-01'], ['ADJ-2', -10_000, '2026-10-02']] as [$ref, $amount, $date]) {
        DB::table('fee_adjustments')->insert([
            'reference_no' => $ref.'-'.Str::upper(Str::random(6)),
            'invoice_line_id' => $invoice['lineId'],
            'enrollment_id' => $s['enrollment']->id,
            'student_id' => $s['enrollment']->student_id,
            'academic_year_id' => $s['enrollment']->academic_year_id,
            'fiscal_year_id' => $s['fiscalYearId'],
            'amount' => $amount,
            'reason_type' => $amount > 0 ? 'hardship' : 'surcharge_late_payment',
            'reason_note' => 'Test adjustment.',
            'adjustment_account_id' => $account->id,
            'application_method' => 'earliest_first',
            'effective_date' => $date,
            'status' => 'approved',
            'granted_by' => $s['user']->getKey(),
            'approved_by' => $s['user']->getKey(),
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $creditNoteId = (int) DB::table('credit_notes')->insertGetId([
        'credit_note_no' => 'AV/2026/'.Str::upper(Str::random(6)),
        'invoice_id' => $invoice['invoiceId'],
        'enrollment_id' => $s['enrollment']->id,
        'student_id' => $s['enrollment']->student_id,
        'academic_year_id' => $s['enrollment']->academic_year_id,
        'fiscal_year_id' => $s['fiscalYearId'],
        'issue_date' => '2026-11-01',
        'reason_type' => 'over_invoiced',
        'reason_note' => 'ICT fee over-billed.',
        'status' => 'issued',
        'settlement_mode' => 'apply_to_account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('credit_note_lines')->insert([
        'credit_note_id' => $creditNoteId,
        'invoice_line_id' => $invoice['lineId'],
        'description' => 'ICT fee correction',
        'amount' => 15_000,
        'tax_amount' => 0,
        'collection_basis' => 'own_revenue',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rows = app(StudentStatement::class)->handle((int) $s['enrollment']->id, '2026-12-31');

    // 350 000 − 50 000 + 10 000 − 15 000 = 295 000
    expect($rows)->toHaveCount(4)
        ->and($rows[1]?->credit)->toBe(50_000)
        ->and($rows[2]?->debit)->toBe(10_000)
        ->and($rows[3]?->credit)->toBe(15_000)
        ->and($rows->last()?->balance)->toBe(295_000);
});

it('cuts off strictly at as_of', function () {
    $s = statementScaffold();
    $invoice = statementInvoice($s['enrollment'], $s['fiscalYearId'], 350_000, '2026-09-10');
    statementPayment($s['enrollment'], $s['fiscalYearId'], 100_000, '2026-11-15', $s['user'], $invoice['invoiceId'], $invoice['lineId']);

    $rows = app(StudentStatement::class)->handle((int) $s['enrollment']->id, '2026-10-31');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]?->balance)->toBe(350_000);
});
