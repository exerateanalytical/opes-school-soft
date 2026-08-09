<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
 * Shared fixtures for the Phase 11 F4 suites (declarations, leave,
 * termination, payment disbursement). Prefix p11decl, every helper
 * function_exists-guarded (00-core test discipline).
 *
 * The staff.* / payroll.* permissions are granted DIRECTLY (Spatie rows):
 * the Phase 11 wiring package (F5) owns the Permission enum cases and role
 * mapping, while these suites exercise the Actions' own gates.
 */

if (! function_exists('p11declUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function p11declUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p11declActor')) {
    function p11declActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('p11declStaff')) {
    /**
     * @param  array<string, mixed>  $attrs
     */
    function p11declStaff(array $attrs = []): StaffMember
    {
        return StaffMember::factory()->create($attrs);
    }
}

if (! function_exists('p11declContract')) {
    /**
     * @param  array<string, mixed>  $attrs
     */
    function p11declContract(?StaffMember $staff = null, array $attrs = []): StaffContract
    {
        return StaffContract::factory()->create(array_merge(
            $staff !== null ? ['staff_member_id' => $staff->id] : [],
            $attrs,
        ));
    }
}

if (! function_exists('p11declCalendar')) {
    /**
     * Open fiscal year + accounting period + academic year covering $date.
     *
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function p11declCalendar(string $date = '2031-03-15'): array
    {
        return (new Database\Factories\JournalEntryFactory)->buildCalendar(Carbon::parse($date));
    }
}

if (! function_exists('p11declRun')) {
    /**
     * A payroll run row in the given status (fixture only - real runs are
     * born through CalculatePayrollRun, which is the F3 package's scope).
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}|null  $calendar
     */
    function p11declRun(
        string $month = '2031-03-01',
        string $status = 'approved',
        ?array $calendar = null,
    ): PayrollRun {
        $calendar ??= p11declCalendar(Carbon::parse($month)->day(15)->toDateString());

        return PayrollRun::factory()->create([
            'payroll_month' => $month,
            'status' => $status,
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'academic_year_id' => $calendar['academic_year_id'],
            'accounting_period_id' => $calendar['accounting_period_id'],
            'approved_at' => $status === 'draft' ? null : now(),
            'idempotency_key' => 'p11decl-'.Str::random(24),
        ]);
    }
}

if (! function_exists('p11declItem')) {
    /**
     * One payroll item row under $run. Direct insert: the item table is the
     * F3 package's model surface; these suites only need rows to exist.
     *
     * @param  array<string, mixed>  $attrs
     */
    function p11declItem(
        PayrollRun $run,
        StaffMember $staff,
        StaffContract $contract,
        int $net = 150000,
        array $attrs = [],
    ): int {
        return (int) DB::table('payroll_items')->insertGetId(array_merge([
            'payroll_run_id' => $run->id,
            'staff_member_id' => $staff->id,
            'staff_contract_id' => $contract->id,
            'payroll_month' => $run->payroll_month->toDateString(),
            'is_cancelled' => false,
            'days_worked' => '26.00',
            'days_in_period' => '26.00',
            'hours_validated' => null,
            'gross' => $net,
            'sbt' => $net,
            'cnps_capped_base' => $net,
            'cnps_uncapped_base' => $net,
            'taxable_base' => $net,
            'irpp_amount' => 0,
            'total_employee_deductions' => 0,
            'total_employer_charges' => 0,
            'net' => $net,
            'ytd_sbt' => $net,
            'ytd_irpp_withheld' => 0,
            'exception_flags' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }
}

if (! function_exists('p11declSnapshot')) {
    /**
     * The immutable approval snapshot for an item (10.2).
     *
     * @param  array<string, mixed>  $payload
     */
    function p11declSnapshot(int $itemId, array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        DB::table('payroll_item_snapshots')->insert([
            'payroll_item_id' => $itemId,
            'snapshot_version' => 1,
            'payload' => $json,
            'payload_hash' => hash('sha256', $json),
            'created_at' => now(),
        ]);
    }
}

if (! function_exists('p11declAccount')) {
    /** A postable chart account. */
    function p11declAccount(): ChartOfAccount
    {
        return ChartOfAccount::factory()->create();
    }
}

if (! function_exists('p11declConfigureAccrualRate')) {
    /**
     * Operator step the seeds deliberately do NOT perform (0 standing
     * rule): configuring the verified accrual rate / entitlement.
     */
    function p11declConfigureAccrualRate(string $rate = '1.50', ?int $statutoryDays = null): void
    {
        DB::table('leave_types')->where('code', 'conge_annuel')->update([
            'monthly_accrual_days' => $rate,
            'statutory_days' => $statutoryDays,
        ]);
    }
}

if (! function_exists('p11declPaidRule')) {
    /**
     * The school's payroll.paid PostingRule through the REAL SavePostingRule
     * gate: Dr staff payable (literal), Cr treasury (payload path). Returns
     * the payable account so assertions can find the debit leg.
     */
    function p11declPaidRule(User $configurer): ChartOfAccount
    {
        $payable = p11declAccount();
        $journal = Journal::factory()->create();

        app(SavePostingRule::class)->handle([
            'code' => 'p11decl_payroll_paid',
            'event' => PostingEvent::PayrollPaid->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Paie {payment.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::Literal,
                'account_code' => $payable->code,
                'sign' => LineSign::Debit,
                'amount_expression' => 'payment.amount',
                'label_expression' => 'Salaires nets — {payment.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'payment.treasury_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'payment.amount',
                'label_expression' => 'Trésorerie — {payment.reference}',
            ],
        ], p11declActor($configurer));

        return $payable;
    }
}

if (! function_exists('p11declProvisionRule')) {
    /** The payroll.leave_provision PostingRule (Dr 66x / Cr 428x by payload path). */
    function p11declProvisionRule(User $configurer): void
    {
        $journal = Journal::factory()->create();

        app(SavePostingRule::class)->handle([
            'code' => 'p11decl_leave_provision',
            'event' => PostingEvent::PayrollLeaveProvision->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Provision congés {provision.month}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'provision.expense_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'provision.amount',
                'label_expression' => 'Charge congés payés',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'provision.liability_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'provision.amount',
                'label_expression' => 'Dette provisionnée congés',
            ],
        ], p11declActor($configurer));
    }
}
