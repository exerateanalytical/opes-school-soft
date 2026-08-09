<?php

declare(strict_types=1);

use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Fees\Actions\ThirdPartyFundsReport;
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

if (! function_exists('tpfUserAs')) {
    function tpfUserAs(bool $withPermission = true): User
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

if (! function_exists('tpfScaffold')) {
    /**
     * @return array{enrollment: Enrollment, fiscalYearId: int, user: User, fundId: int}
     */
    function tpfScaffold(): array
    {
        $user = tpfUserAs();
        actingAs($user);

        return [
            'enrollment' => Enrollment::factory()->create(),
            'fiscalYearId' => (int) FiscalYear::factory()->open()->create()->getKey(),
            'user' => $user,
            'fundId' => tpfFund('APEE'),
        ];
    }
}

if (! function_exists('tpfFund')) {
    function tpfFund(string $code): int
    {
        return (int) DB::table('third_party_funds')->insertGetId([
            'code' => $code.'-'.Str::upper(Str::random(6)),
            'name' => 'Fund '.$code,
            'name_fr' => 'Fonds '.$code,
            'beneficiary_type' => 'apee',
            'beneficiary_name' => 'APEE Bureau',
            'liability_account_id' => ChartOfAccount::factory()->create()->id,
            'remittance_frequency' => 'termly',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('tpfInvoice')) {
    /**
     * ISSUED invoice with ONE agent line for the fund and ONE own-revenue
     * line - the report must count only the agent leg (C5).
     *
     * @return array{invoiceId: int, agentLineId: int, ownLineId: int}
     */
    function tpfInvoice(
        Enrollment $enrollment,
        int $fiscalYearId,
        int $fundId,
        int $agentAmount,
        int $ownAmount,
        string $issueDate,
        ?int $termId = null,
    ): array {
        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'invoice_no' => 'INV/2026/'.Str::upper(Str::random(8)),
            'term_id' => $termId,
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'academic_year_id' => $enrollment->academic_year_id,
            'fiscal_year_id' => $fiscalYearId,
            'type' => 'standard',
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $agentLineId = (int) DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'line_no' => 1,
            'description' => 'APEE Levy',
            'collection_basis' => 'agent_for_third_party',
            'third_party_fund_id' => $fundId,
            'recognition_method' => 'on_issue',
            'quantity' => 1,
            'unit_amount' => $agentAmount,
            'amount' => $agentAmount,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownLineId = (int) DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'line_no' => 2,
            'description' => 'Tuition Fee',
            'collection_basis' => 'own_revenue',
            'revenue_account_id' => ChartOfAccount::factory()->create()->id,
            'recognition_method' => 'on_issue',
            'quantity' => 1,
            'unit_amount' => $ownAmount,
            'amount' => $ownAmount,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['invoiceId' => $invoiceId, 'agentLineId' => $agentLineId, 'ownLineId' => $ownLineId];
    }
}

if (! function_exists('tpfPayment')) {
    function tpfPayment(
        Enrollment $enrollment,
        int $fiscalYearId,
        int $amount,
        string $valueDate,
        User $receivedBy,
        int $invoiceId,
        int $lineId,
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
            'unallocated_amount' => 0,
            'received_by' => $receivedBy->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

        return $paymentId;
    }
}

if (! function_exists('tpfRemittance')) {
    function tpfRemittance(
        int $fundId,
        int $amount,
        string $periodStart,
        string $periodEnd,
        string $remittedOn,
        string $status = 'remitted',
    ): int {
        return (int) DB::table('third_party_fund_remittances')->insertGetId([
            'third_party_fund_id' => $fundId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'amount_collected' => $amount,
            'amount_remitted' => $amount,
            'remitted_on' => $status === 'remitted' ? $remittedOn : null,
            'method' => 'bank_transfer',
            'reference' => 'REM/'.Str::upper(Str::random(8)),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('refuses the funds report without fee.view', function () {
    $user = tpfUserAs(withPermission: false);
    actingAs($user);

    app(ThirdPartyFundsReport::class)->handle('2026-09-01', '2026-12-31');
})->throws(AuthorizationException::class);

it('counts only allocations to agent lines as collected - never own revenue', function () {
    $s = tpfScaffold();
    $invoice = tpfInvoice($s['enrollment'], $s['fiscalYearId'], $s['fundId'], 15_000, 350_000, '2026-09-10');

    tpfPayment($s['enrollment'], $s['fiscalYearId'], 15_000, '2026-10-01', $s['user'], $invoice['invoiceId'], $invoice['agentLineId']);
    tpfPayment($s['enrollment'], $s['fiscalYearId'], 350_000, '2026-10-01', $s['user'], $invoice['invoiceId'], $invoice['ownLineId']);

    $rows = app(ThirdPartyFundsReport::class)->handle('2026-09-01', '2026-12-31', $s['fundId']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]?->collected)->toBe(15_000)
        ->and($rows[0]?->opening_held)->toBe(0)
        ->and($rows[0]?->remitted)->toBe(0)
        ->and($rows[0]?->closing_held)->toBe(15_000);
});

it('excludes voided and bounced payments from collection (§5.2)', function () {
    $s = tpfScaffold();
    $invoice = tpfInvoice($s['enrollment'], $s['fiscalYearId'], $s['fundId'], 15_000, 0, '2026-09-10');

    $voidedId = tpfPayment($s['enrollment'], $s['fiscalYearId'], 15_000, '2026-10-01', $s['user'], $invoice['invoiceId'], $invoice['agentLineId']);
    DB::table('payment_voids')->insert([
        'payment_id' => $voidedId,
        'reason_type' => 'duplicate',
        'reason_note' => 'Keyed twice.',
        'voided_by' => $s['user']->getKey(),
        'voided_at' => '2026-10-02 09:00:00',
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bouncedId = tpfPayment($s['enrollment'], $s['fiscalYearId'], 15_000, '2026-10-05', $s['user'], $invoice['invoiceId'], $invoice['agentLineId']);
    DB::table('payments')->where('id', $bouncedId)->update([
        'clearing_state' => 'bounced',
        'bounced_on' => '2026-10-20',
        'bounce_reason' => 'Insufficient funds',
    ]);

    $good = tpfPayment($s['enrollment'], $s['fiscalYearId'], 15_000, '2026-10-10', $s['user'], $invoice['invoiceId'], $invoice['agentLineId']);

    $rows = app(ThirdPartyFundsReport::class)->handle('2026-09-01', '2026-12-31', $s['fundId']);

    expect($rows[0]?->collected)->toBe(15_000)
        ->and($good)->toBeGreaterThan(0);
});

it('carries prior collections into opening_held and subtracts remittances in the window', function () {
    $s = tpfScaffold();

    // Distinct terms keep the two issued invoices apart under the
    // issue-idempotency UNIQUE (enrollment, structure, term).
    $term1 = AssessmentPeriod::factory()->term(1, '2026-09-01', '2026-12-31')->create([
        'academic_year_id' => $s['enrollment']->academic_year_id,
    ]);
    $term2 = AssessmentPeriod::factory()->term(2, '2027-01-01', '2027-03-31')->create([
        'academic_year_id' => $s['enrollment']->academic_year_id,
    ]);

    // Term 1 (before the reporting window): collect 60 000, remit 40 000.
    $t1 = tpfInvoice($s['enrollment'], $s['fiscalYearId'], $s['fundId'], 60_000, 0, '2026-09-05', (int) $term1->getKey());
    tpfPayment($s['enrollment'], $s['fiscalYearId'], 60_000, '2026-09-20', $s['user'], $t1['invoiceId'], $t1['agentLineId']);
    tpfRemittance($s['fundId'], 40_000, '2026-09-01', '2026-12-31', '2026-12-15');

    // Term 2 (the window): collect 30 000, remit 25 000.
    $t2 = tpfInvoice($s['enrollment'], $s['fiscalYearId'], $s['fundId'], 30_000, 0, '2027-01-10', (int) $term2->getKey());
    tpfPayment($s['enrollment'], $s['fiscalYearId'], 30_000, '2027-01-20', $s['user'], $t2['invoiceId'], $t2['agentLineId']);
    tpfRemittance($s['fundId'], 25_000, '2027-01-01', '2027-03-31', '2027-03-20');

    $rows = app(ThirdPartyFundsReport::class)->handle('2027-01-01', '2027-03-31', $s['fundId']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]?->opening_held)->toBe(20_000)
        ->and($rows[0]?->collected)->toBe(30_000)
        ->and($rows[0]?->remitted)->toBe(25_000)
        ->and($rows[0]?->closing_held)->toBe(25_000);
});

it('ignores draft remittances - only remitted rows move the balance', function () {
    $s = tpfScaffold();
    $invoice = tpfInvoice($s['enrollment'], $s['fiscalYearId'], $s['fundId'], 50_000, 0, '2026-09-10');
    tpfPayment($s['enrollment'], $s['fiscalYearId'], 50_000, '2026-10-01', $s['user'], $invoice['invoiceId'], $invoice['agentLineId']);

    tpfRemittance($s['fundId'], 50_000, '2026-09-01', '2026-12-31', '2026-12-15', status: 'draft');

    $rows = app(ThirdPartyFundsReport::class)->handle('2026-09-01', '2026-12-31', $s['fundId']);

    expect($rows[0]?->remitted)->toBe(0)
        ->and($rows[0]?->closing_held)->toBe(50_000);
});

it('reports every fund, zero-movement funds included, ordered by code', function () {
    $s = tpfScaffold();
    $second = tpfFund('EXAM');

    $rows = app(ThirdPartyFundsReport::class)->handle('2026-09-01', '2026-12-31');

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('fund_id')->sort()->values()->all())->toBe([$s['fundId'], $second])
        ->and($rows[0]?->closing_held)->toBe(0)
        ->and($rows[1]?->closing_held)->toBe(0);
});
