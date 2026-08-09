<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Fees\Actions\AgedBalances;
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

if (! function_exists('agedUserAs')) {
    function agedUserAs(bool $withPermission = true): User
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

if (! function_exists('agedScaffold')) {
    /**
     * @return array{enrollment: Enrollment, fiscalYearId: int, user: User}
     */
    function agedScaffold(): array
    {
        $user = agedUserAs();
        actingAs($user);

        return [
            'enrollment' => Enrollment::factory()->create(),
            'fiscalYearId' => (int) FiscalYear::factory()->open()->create()->getKey(),
            'user' => $user,
        ];
    }
}

if (! function_exists('agedInvoice')) {
    /**
     * An invoice with one line for the given total; instalments optional as
     * [amount, due_date] pairs (aging is by instalment due date, §11.3).
     *
     * @param  list<array{0: int, 1: string}>  $installments
     * @return array{invoiceId: int, lineId: int}
     */
    function agedInvoice(
        Enrollment $enrollment,
        int $fiscalYearId,
        int $amount,
        string $issueDate,
        string $dueDate,
        array $installments = [],
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
            'due_date' => $dueDate,
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

        foreach ($installments as $index => [$tranche, $due]) {
            DB::table('invoice_installments')->insert([
                'invoice_id' => $invoiceId,
                'sequence_no' => $index + 1,
                'label' => 'Tranche '.($index + 1),
                'amount' => $tranche,
                'due_date' => $due,
                'is_cancelled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return ['invoiceId' => $invoiceId, 'lineId' => $lineId];
    }
}

if (! function_exists('agedPayment')) {
    function agedPayment(
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

it('refuses aged balances without fee.view', function () {
    $user = agedUserAs(withPermission: false);
    actingAs($user);

    app(AgedBalances::class)->handle('2027-01-01');
})->throws(AuthorizationException::class);

it('buckets each instalment by its own due date at exact boundaries', function () {
    // as_of 2027-01-01. Boundary days: 0 → current; 1 & 30 → 1-30;
    // 31 & 60 → 31-60; 61 & 90 → 61-90; 91 & 180 → 91-180; 181 → 180+.
    $s = agedScaffold();

    agedInvoice($s['enrollment'], $s['fiscalYearId'], 11_000, '2026-06-01', '2026-06-01', [
        [1_000, '2027-01-01'],  // 0 days      → current
        [1_000, '2026-12-31'],  // 1 day       → days_1_30
        [1_000, '2026-12-02'],  // 30 days     → days_1_30
        [1_000, '2026-12-01'],  // 31 days     → days_31_60
        [1_000, '2026-11-02'],  // 60 days     → days_31_60
        [1_000, '2026-11-01'],  // 61 days     → days_61_90
        [1_000, '2026-10-03'],  // 90 days     → days_61_90
        [1_000, '2026-10-02'],  // 91 days     → days_91_180
        [1_000, '2026-07-05'],  // 180 days    → days_91_180
        [1_000, '2026-07-04'],  // 181 days    → days_180_plus
        [1_000, '2027-03-01'],  // future      → current
    ]);

    $rows = app(AgedBalances::class)->handle('2027-01-01');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]?->current)->toBe(2_000)
        ->and($rows[0]?->days_1_30)->toBe(2_000)
        ->and($rows[0]?->days_31_60)->toBe(2_000)
        ->and($rows[0]?->days_61_90)->toBe(2_000)
        ->and($rows[0]?->days_91_180)->toBe(2_000)
        ->and($rows[0]?->days_180_plus)->toBe(1_000)
        ->and($rows[0]?->outstanding)->toBe(11_000)
        ->and($rows[0]?->net)->toBe(11_000);
});

it('fills instalments oldest-sequence-first with cumulative settlement', function () {
    $s = agedScaffold();

    $invoice = agedInvoice($s['enrollment'], $s['fiscalYearId'], 200_000, '2026-06-01', '2026-06-01', [
        [100_000, '2026-09-01'],
        [100_000, '2026-12-15'],
    ]);

    // 130 000 clears tranche 1 entirely and 30 000 of tranche 2 - only
    // the REMAINDER of tranche 2 stays open, in tranche 2's bucket.
    agedPayment($s['enrollment'], $s['fiscalYearId'], 130_000, '2026-10-01', $s['user'], $invoice['invoiceId'], $invoice['lineId']);

    $rows = app(AgedBalances::class)->handle('2027-01-01');

    // 2026-12-15 → 17 days overdue → days_1_30.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]?->days_1_30)->toBe(70_000)
        ->and($rows[0]?->days_91_180)->toBe(0)
        ->and($rows[0]?->outstanding)->toBe(70_000);
});

it('falls back to the header due date when an invoice has no instalments', function () {
    $s = agedScaffold();

    agedInvoice($s['enrollment'], $s['fiscalYearId'], 50_000, '2026-06-01', '2026-11-20');

    $rows = app(AgedBalances::class)->handle('2027-01-01');

    // 42 days overdue → days_31_60.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]?->days_31_60)->toBe(50_000)
        ->and($rows[0]?->outstanding)->toBe(50_000);
});

it('ignores draft and cancelled invoices entirely', function () {
    $s = agedScaffold();

    agedInvoice($s['enrollment'], $s['fiscalYearId'], 100_000, '2026-06-01', '2026-07-01', status: 'draft');
    agedInvoice($s['enrollment'], $s['fiscalYearId'], 200_000, '2026-06-01', '2026-07-01', status: 'cancelled');

    $rows = app(AgedBalances::class)->handle('2027-01-01');

    expect($rows)->toHaveCount(0);
});

it('nets unallocated credit against arrears, and shows pure credit as a NEGATIVE net', function () {
    $s = agedScaffold();

    // Student A: 80 000 overdue, 50 000 sitting unallocated → net 30 000.
    agedInvoice($s['enrollment'], $s['fiscalYearId'], 80_000, '2026-06-01', '2026-11-20');
    agedPayment($s['enrollment'], $s['fiscalYearId'], 50_000, '2026-12-01', $s['user']);

    // Student B: no invoice at all, 40 000 unallocated → net −40 000,
    // never clamped to zero (C9).
    $other = Enrollment::factory()->create();
    agedPayment($other, $s['fiscalYearId'], 40_000, '2026-12-01', $s['user']);

    $rows = app(AgedBalances::class)->handle('2027-01-01');

    expect($rows)->toHaveCount(2);

    $byStudent = $rows->keyBy('student_id');

    $a = $byStudent[(int) $s['enrollment']->student_id] ?? null;
    $b = $byStudent[(int) $other->student_id] ?? null;

    expect($a?->outstanding)->toBe(80_000)
        ->and($a?->unallocated_credit)->toBe(50_000)
        ->and($a?->net)->toBe(30_000)
        ->and($b?->outstanding)->toBe(0)
        ->and($b?->unallocated_credit)->toBe(40_000)
        ->and($b?->net)->toBe(-40_000);
});

it('excludes voided payments from settlement so the tranche re-opens', function () {
    $s = agedScaffold();

    $invoice = agedInvoice($s['enrollment'], $s['fiscalYearId'], 100_000, '2026-06-01', '2026-11-20');
    $paymentId = agedPayment($s['enrollment'], $s['fiscalYearId'], 100_000, '2026-12-01', $s['user'], $invoice['invoiceId'], $invoice['lineId']);

    DB::table('payment_voids')->insert([
        'payment_id' => $paymentId,
        'reason_type' => 'wrong_student',
        'reason_note' => 'Receipt issued to the wrong student.',
        'voided_by' => $s['user']->getKey(),
        'voided_at' => '2026-12-10 09:00:00',
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rows = app(AgedBalances::class)->handle('2027-01-01');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]?->outstanding)->toBe(100_000)
        ->and($rows[0]?->unallocated_credit)->toBe(0);
});
